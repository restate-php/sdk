<?php

declare(strict_types=1);

namespace Restate\Internal;

use Restate\ErrorMapper;
use Restate\HandlerExecution;
use Restate\HandlerInterceptor;
use Restate\PauseError;
use Restate\RetryableError;
use Restate\RunExecution;
use Restate\RunInterceptor;
use Restate\TerminalError;

/**
 * The Runtime Execution Hooks of one service: its interceptor chains around handler attempts and run callbacks, and its
 * error mappers. Endpoint hooks come first: they are the outer interceptors, and the fallback mappers.
 *
 * The chains enforce the interceptor rules (see {@see HandlerInterceptor}): `$next` exactly once, failures pass
 * through, and once the attempt suspended (a {@see SuspensionException} came out of `$next`, or the context's
 * {@see SuspensionGuard} saw one) the suspension is what leaves the chain, whatever the interceptor returned or threw.
 *
 * @internal
 */
final readonly class ExecutionHooks
{
    private const string SUSPENDED = 'The invocation suspended; an interceptor cannot change the outcome of a suspended attempt.';

    /**
     * @param list<HandlerInterceptor> $handlerInterceptors outermost first
     * @param list<RunInterceptor> $runInterceptors outermost first
     * @param list<ErrorMapper> $errorMappers asked in order
     */
    private function __construct(
        private array $handlerInterceptors = [],
        private array $runInterceptors = [],
        private array $errorMappers = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @param list<HandlerInterceptor|RunInterceptor> $interceptors outermost first; an object implementing both
     *                                                              interfaces joins both chains
     */
    public static function of(array $interceptors, ?ErrorMapper $errorMapper = null): self
    {
        return new self(
            array_values(array_filter($interceptors, static fn (object $i): bool => $i instanceof HandlerInterceptor)),
            array_values(array_filter($interceptors, static fn (object $i): bool => $i instanceof RunInterceptor)),
            $errorMapper === null ? [] : [$errorMapper],
        );
    }

    /**
     * These hooks around $inner's: this chain's interceptors are outside $inner's, and $inner's mappers are asked first.
     */
    public function around(self $inner): self
    {
        return new self(
            [...$this->handlerInterceptors, ...$inner->handlerInterceptors],
            [...$this->runInterceptors, ...$inner->runInterceptors],
            [...$inner->errorMappers, ...$this->errorMappers],
        );
    }

    /**
     * Runs $handler inside the handler interceptors and returns its result.
     *
     * @template T
     * @param \Closure(): bool $isSuspended whether the attempt suspended
     * @param \Closure(): T $handler
     * @return T
     */
    public function executeHandler(HandlerExecution $execution, \Closure $isSuspended, \Closure $handler): mixed
    {
        return self::chain(
            array_map(static fn (HandlerInterceptor $i): \Closure => static fn (\Closure $next) => $i->interceptHandler($execution, $next), $this->handlerInterceptors),
            array_map(static fn (HandlerInterceptor $i): string => $i::class, $this->handlerInterceptors),
            $isSuspended,
            $handler,
        );
    }

    /**
     * Runs a run's callback inside the run interceptors and returns its result.
     *
     * @template T
     * @param \Closure(): bool $isSuspended whether the attempt suspended
     * @param \Closure(): T $callback
     * @return T
     */
    public function executeRun(RunExecution $execution, \Closure $isSuspended, \Closure $callback): mixed
    {
        return self::chain(
            array_map(static fn (RunInterceptor $i): \Closure => static fn (\Closure $next) => $i->interceptRun($execution, $next), $this->runInterceptors),
            array_map(static fn (RunInterceptor $i): string => $i::class, $this->runInterceptors),
            $isSuspended,
            $callback,
        );
    }

    /**
     * Classifies $error with the error mappers: the first mapper that returns an error decides. Never maps a
     * suspension, a {@see TerminalError}, a {@see RetryableError} or a {@see PauseError}.
     */
    public function mapError(\Throwable $error): TerminalError|RetryableError|PauseError|null
    {
        if ($error instanceof SuspensionException || $error instanceof TerminalError || $error instanceof RetryableError || $error instanceof PauseError) {
            return null;
        }

        foreach ($this->errorMappers as $mapper) {
            $mapped = $mapper->map($error);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    /**
     * @template T
     * @param list<\Closure(\Closure(): void): void> $layers outermost first
     * @param list<string> $names the interceptor class of each layer, for error messages
     * @param \Closure(): bool $isSuspended
     * @param \Closure(): T $body
     * @return T
     */
    private static function chain(array $layers, array $names, \Closure $isSuspended, \Closure $body): mixed
    {
        if ($layers === []) {
            return $body();
        }

        $result = null;
        $call = static function () use (&$result, $body): void {
            $result = $body();
        };
        for ($i = count($layers) - 1; $i >= 0; --$i) {
            $call = self::layer($layers[$i], $names[$i], $call, $isSuspended);
        }

        $call();

        /** @var T $result */
        return $result;
    }

    /**
     * @param \Closure(\Closure(): void): void $intercept
     * @param \Closure(): void $inner
     * @param \Closure(): bool $isSuspended
     * @return \Closure(): void
     */
    private static function layer(\Closure $intercept, string $name, \Closure $inner, \Closure $isSuspended): \Closure
    {
        return static function () use ($intercept, $name, $inner, $isSuspended): void {
            $called = false;
            $failure = null;
            $next = static function () use (&$called, &$failure, $inner, $name, $isSuspended): void {
                if ($called) {
                    throw new \LogicException("Interceptor {$name} called \$next more than once.");
                }
                $called = true;
                try {
                    $inner();
                } catch (\Throwable $e) {
                    $failure = $e;
                    throw $e;
                }

                // The handler (or callback) caught the suspension and returned: the interceptor still sees a suspension.
                if ($isSuspended()) {
                    $failure = new SuspensionException(self::SUSPENDED);
                    throw $failure;
                }
            };

            try {
                $intercept($next);
            } catch (\Throwable $e) {
                self::rethrowSuspension($failure, $isSuspended);
                throw $e;
            }

            self::rethrowSuspension($failure, $isSuspended);
            if ($failure !== null) {
                // An interceptor cannot turn a failure into a success.
                throw $failure;
            }
            if (!$called) {
                throw new \LogicException("Interceptor {$name} returned without calling \$next.");
            }
        };
    }

    /** @param \Closure(): bool $isSuspended */
    private static function rethrowSuspension(?\Throwable $failure, \Closure $isSuspended): void
    {
        if ($failure instanceof SuspensionException) {
            throw $failure;
        }
        if ($isSuspended()) {
            throw new SuspensionException(self::SUSPENDED, previous: $failure);
        }
    }
}
