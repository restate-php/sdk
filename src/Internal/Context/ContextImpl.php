<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Restate\AttachTarget;
use Restate\Awakeable;
use Restate\CallOptions;
use Restate\Context;
use Restate\Internal\DeterministicRandom;
use Restate\DurableFuture;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Internal\ExecutionHooks;
use Restate\Internal\FlowControlKeys;
use Restate\Internal\InvocationOperations;
use Restate\Internal\JournalValueCodecResolver;
use Restate\Internal\SdkLog;
use Restate\Internal\RequestHeaders;
use Restate\Internal\ResultDecoder;
use Restate\Internal\SerdeResolver;
use Restate\Internal\SuspensionException;
use Restate\Internal\SuspensionGuard;
use Restate\InvocationHandle;
use Restate\JournalValueCodec;
use Restate\JsonSerde;
use Restate\Logging\ReplayAwareLogger;
use Restate\ObjectClient;
use Restate\Internal\ReplayStateProvider;
use Restate\Request;
use Restate\OnMaxAttempts;
use Restate\PauseError;
use Restate\RetryableError;
use Restate\RunExecution;
use Restate\RunOptions;
use Restate\RunRetryPolicy;
use Restate\Scope;
use Restate\SendOptions;
use Restate\Serde;
use Restate\ServiceClient;
use Restate\Target;
use Restate\TerminalError;
use Restate\WorkflowClient;

/**
 * The {@see Context} of a service handler, and the base of the keyed contexts.
 *
 * @internal
 */
class ContextImpl implements Context
{
    private ?DeterministicRandom $random = null;

    private readonly ExecutionHooks $hooks;

    private ?ReplayAwareLogger $logger = null;

    /**
     * @param Serde|class-string<Serde>|null $defaultSerde
     * @param JournalValueCodec|class-string<JournalValueCodec>|null $journalValueCodec
     */
    public function __construct(
        private readonly InvocationOperations $vm,
        private readonly Request $request,
        private readonly int $randomSeed = 0,
        private readonly Serde|string|null $defaultSerde = null,
        private readonly JournalValueCodec|string|null $journalValueCodec = null,
        ?ExecutionHooks $hooks = null,
        private readonly LoggerInterface $endpointLogger = new NullLogger(),
    ) {
        $this->hooks = $hooks ?? ExecutionHooks::none();
    }

    public function serviceClient(string $service): ServiceClient
    {
        return new ServiceClient($this, $service);
    }

    public function objectClient(string $object, string $key): ObjectClient
    {
        return new ObjectClient($this, $object, $key);
    }

    public function workflowClient(string $workflow, string $key): WorkflowClient
    {
        return new WorkflowClient($this, $workflow, $key);
    }

    public function scope(string $scope): Scope
    {
        return new Scope($this, $scope);
    }

    public function randomSeed(): int
    {
        return $this->randomSeed;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function isReplaying(): bool
    {
        return $this->vm instanceof ReplayStateProvider && $this->vm->isReplaying();
    }

    public function logger(): LoggerInterface
    {
        return $this->logger ??= new ReplayAwareLogger($this, $this->endpointLogger);
    }

    public function randInt(int $min, int $max): int
    {
        return $this->random()->nextInt($min, $max);
    }

    public function randBytes(int $length): string
    {
        return $this->random()->nextBytes($length);
    }

    public function uuid(): string
    {
        $bytes = $this->randBytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public function now(): int
    {
        $timestamp = $this->run('date.now', static fn (): int => (int) floor(microtime(true) * 1000), serde: JsonSerde::default());
        if (!is_int($timestamp)) {
            throw new \RuntimeException('Context::now() returned an invalid timestamp.');
        }

        return $timestamp;
    }

    /** @param Serde|class-string<Serde>|null $serde */
    public function awakeable(Serde|string|null $serde = null): Awakeable
    {
        $awakeable = $this->operations()->sysAwakeable();
        return new AwakeableImpl($this->operations(), $awakeable->id, $awakeable->handle, $this->serde($serde), $this->codec());
    }

    /** @param Serde|class-string<Serde>|null $serde */
    public function resolveAwakeable(string $id, mixed $value = null, Serde|string|null $serde = null): void
    {
        $this->operations()->sysResolveAwakeable($id, $this->encode($this->serde($serde)->serialize($value)));
    }

    public function rejectAwakeable(string $id, TerminalError $failure): void
    {
        $this->operations()->sysRejectAwakeable($id, $failure->statusCode(), $failure->getMessage(), $failure->metadata());
    }

    /** @param Serde|class-string<Serde>|null $serde */
    public function signal(string $name, Serde|string|null $serde = null): DurableFuture
    {
        SignalHandleImpl::assertValidName($name);

        return self::futureResult($this->operations(), $this->operations()->sysSignal($name), $this->serde($serde), $this->codec(), 'signal', 'Signal suspended.');
    }

    /** @param Serde|class-string<Serde>|null $serde */
    public function invocation(string $invocationId, Serde|string|null $serde = null): InvocationHandle
    {
        return new InvocationHandleImpl($this->operations(), AttachTarget::invocationId($invocationId), $invocationId, serde: $this->serde($serde), journalValueCodec: $this->codec(), signalSerde: $this->serde(null));
    }

    /** @param Serde|class-string<Serde>|null $serde */
    public function attach(AttachTarget $target, Serde|string|null $serde = null): InvocationHandle
    {
        return new InvocationHandleImpl($this->operations(), $target, serde: $this->serde($serde), journalValueCodec: $this->codec(), signalSerde: $this->serde(null));
    }

    /**
     * @param Serde|class-string<Serde>|null $inputSerde
     * @param Serde|class-string<Serde>|null $outputSerde
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     */
    public function callFuture(Target $target, mixed $payload, ?string $idempotencyKey = null, Serde|string|null $inputSerde = null, Serde|string|null $outputSerde = null, array $headers = [], ?string $limitKey = null): DurableFuture
    {
        self::assertValidIdempotencyKey($idempotencyKey);
        $handle = $this->operations()->sysCall(self::callTarget($target, $headers, $limitKey), $this->encode($this->serde($inputSerde)->serialize($payload)), $idempotencyKey);

        return self::futureResult($this->operations(), $handle, $this->serde($outputSerde), $this->codec(), 'service call', 'Service call suspended.');
    }

    /**
     * @param Serde|class-string<Serde>|null $inputSerde
     * @param Serde|class-string<Serde>|null $outputSerde
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     */
    public function call(Target $target, mixed $payload, ?string $idempotencyKey = null, Serde|string|null $inputSerde = null, Serde|string|null $outputSerde = null, array $headers = [], ?string $limitKey = null): mixed
    {
        return $this->callFuture($target, $payload, $idempotencyKey, $inputSerde, $outputSerde, $headers, $limitKey)->awaitResult();
    }

    public function genericCall(Target $target, mixed $payload, ?CallOptions $options = null): mixed
    {
        $options ??= CallOptions::none();

        return $this->call($target, $payload, $options->idempotencyKey, $options->inputSerde, $options->outputSerde, $options->headers, $options->limitKey);
    }

    /**
     * @param Serde|class-string<Serde>|null $inputSerde
     * @param Serde|class-string<Serde>|null $outputSerde
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     */
    public function send(Target $target, mixed $payload, ?string $idempotencyKey = null, Serde|string|null $inputSerde = null, Serde|string|null $outputSerde = null, array $headers = [], ?string $limitKey = null): InvocationHandle
    {
        self::assertValidIdempotencyKey($idempotencyKey);
        $handle = $this->operations()->sysSend(self::callTarget($target, $headers, $limitKey), $this->encode($this->serde($inputSerde)->serialize($payload)), null, $idempotencyKey);
        return InvocationHandleImpl::pending($this->operations(), $handle, $this->serde($outputSerde), $this->codec(), $this->serde(null));
    }

    /**
     * @param Serde|class-string<Serde>|null $inputSerde
     * @param Serde|class-string<Serde>|null $outputSerde
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     */
    public function sendAfter(Target $target, mixed $payload, int $delayMillis, ?string $idempotencyKey = null, Serde|string|null $inputSerde = null, Serde|string|null $outputSerde = null, array $headers = [], ?string $limitKey = null): InvocationHandle
    {
        if ($delayMillis < 0) {
            throw new InvalidArgumentException('Send delay must be greater than or equal to 0 milliseconds.');
        }
        self::assertValidIdempotencyKey($idempotencyKey);

        $handle = $this->operations()->sysSend(self::callTarget($target, $headers, $limitKey), $this->encode($this->serde($inputSerde)->serialize($payload)), $delayMillis, $idempotencyKey);
        return InvocationHandleImpl::pending($this->operations(), $handle, $this->serde($outputSerde), $this->codec(), $this->serde(null));
    }

    public function genericSend(Target $target, mixed $payload, ?SendOptions $options = null): InvocationHandle
    {
        $options ??= SendOptions::none();

        return $options->delayMillis === null
            ? $this->send($target, $payload, $options->idempotencyKey, $options->inputSerde, $options->outputSerde, $options->headers, $options->limitKey)
            : $this->sendAfter($target, $payload, $options->delayMillis, $options->idempotencyKey, $options->inputSerde, $options->outputSerde, $options->headers, $options->limitKey);
    }

    /** @param array<string, string>|list<array{0: string, 1: string}> $headers */
    private static function callTarget(Target $target, array $headers, ?string $limitKey): CallTarget
    {
        FlowControlKeys::assertLimitKeyFor($target->scope(), $limitKey);

        return new CallTarget($target, RequestHeaders::normalize($headers), $limitKey);
    }

    private static function assertValidIdempotencyKey(?string $idempotencyKey): void
    {
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key must not be empty.');
        }
    }

    public function sleep(int $delayMillis): void
    {
        $this->sleepFuture($delayMillis)->awaitResult();
    }

    public function sleepFuture(int $delayMillis): DurableFuture
    {
        if ($delayMillis < 0) {
            throw new InvalidArgumentException('Sleep delay must be greater than or equal to 0 milliseconds.');
        }

        return DurableFutureImpl::voidResult($this->operations(), $this->operations()->sysSleep($delayMillis), 'sleep', 'Sleep suspended.');
    }

    /**
     * @param Serde|class-string<Serde>|null $serde
     * @return array{id: string, future: DurableFuture}
     */
    public function awakeableFuture(Serde|string|null $serde = null): array
    {
        $awakeable = $this->operations()->sysAwakeable();

        return [
            'id' => $awakeable->id,
            'future' => self::futureResult($this->operations(), $awakeable->handle, $this->serde($serde), $this->codec(), 'awakeable', 'Awakeable suspended.'),
        ];
    }

    /** @param array<array-key, DurableFuture> $futures */
    public function select(array $futures): int|string
    {
        return $this->futures()->select($futures);
    }

    /**
     * @param array<array-key, DurableFuture> $futures
     * @return array<array-key, mixed>
     */
    public function all(array $futures): array
    {
        return $this->futures()->all($futures);
    }

    /** @param array<array-key, DurableFuture> $futures */
    public function race(array $futures): mixed
    {
        return $this->futures()->race($futures);
    }

    /** @param array<array-key, DurableFuture> $futures */
    public function any(array $futures): mixed
    {
        return $this->futures()->any($futures);
    }

    /**
     * @param array<array-key, DurableFuture> $futures
     * @return array<array-key, array{status: 'fulfilled', value: mixed}|array{status: 'rejected', reason: \Throwable}>
     */
    public function allSettled(array $futures): array
    {
        return $this->futures()->allSettled($futures);
    }

    /**
     * @param array<array-key, DurableFuture> $futures
     * @return array{completed: array<array-key, DurableFuture>, pending: array<array-key, DurableFuture>}
     */
    public function waitCompleted(array $futures): array
    {
        return $this->futures()->waitCompleted($futures);
    }

    private function futures(): FutureCombinators
    {
        return new FutureCombinators($this->operations());
    }

    /** @param Serde|class-string<Serde>|null $serde */
    public function run(string $name, callable $callback, RunRetryPolicy|RunOptions|null $retryPolicy = null, Serde|string|null $serde = null): mixed
    {
        if ($retryPolicy instanceof RunOptions) {
            $serde ??= $retryPolicy->serde;
            $retryPolicy = $retryPolicy->retryPolicy;
        }

        $serde = $this->serde($serde);
        $result = ResultDecoder::serde('ctx.run', $serde, $this->codec());

        $vm = $this->operations();
        $handle = $vm->sysRun($name);
        $state = $vm->doAwait($handle);
        if ($state->state !== AwaitState::ExecuteRun) {
            // Replayed: the journal holds the run's result.
            ResultDecoder::assertCompleted($state, 'ctx.run', 'Run suspended.');

            return $result->decode($vm->takeNotificationResult($handle));
        }

        $policy = $retryPolicy ?? RunRetryPolicy::infinite();
        $attemptStartedAt = (int) hrtime(true);
        $attemptDurationMillis = static fn (): int => max(0, intdiv((int) hrtime(true) - $attemptStartedAt, 1_000_000));

        try {
            // The run interceptors wrap only an executing callback, never a replayed result.
            $value = $this->hooks->executeRun(new RunExecution($this->request, $name), $this->isSuspended(...), \Closure::fromCallable($callback));
        } catch (SuspensionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $failure = $this->hooks->mapError($e) ?? $e;
            if ($failure instanceof TerminalError) {
                $vm->proposeRunCompletionFailure($handle, $failure->statusCode(), $failure->getMessage(), $policy, $failure->metadata());
                ResultDecoder::assertCompleted($vm->doAwait($handle), 'ctx.run completion', 'Run completion suspended.');
                throw $failure;
            }

            if ($failure instanceof PauseError) {
                // The core ends the attempt for Restate to pause the invocation; the run executes again once resumed.
                $vm->proposeRunCompletionPause($handle, $failure->getMessage(), $attemptDurationMillis());
                $this->logRunFailure($name, $failure, "Run '{$name}' paused the invocation; it waits until it is resumed");

                throw new SuspensionException('Run paused the invocation.', previous: $failure);
            }

            $status = $failure instanceof RetryableError
                ? $vm->proposeRunCompletionRetryable($handle, $failure->getMessage(), $attemptDurationMillis(), $policy, $failure->retryAfterMillis(), $failure->getCode(), $failure->metadata())
                : $vm->proposeRunCompletionRetryable($handle, $failure->getMessage(), $attemptDurationMillis(), $policy);
            if ($status === RunCompletionStatus::AttemptEnded) {
                // Under a policy that pauses once exhausted the core cannot tell a scheduled retry from a pause
                // (docs/decisions/0021-pause.md), so the messages claim neither.
                $mayPause = ($policy->toArray()['on_max_attempts'] ?? null) === OnMaxAttempts::Pause->value;
                $this->logRunFailure($name, $failure, $mayPause
                    ? "Run '{$name}' failed; Restate retries it, or pauses the invocation once the run retry policy is exhausted"
                    : "Run '{$name}' failed; Restate retries it");

                throw new SuspensionException(
                    $mayPause
                        ? 'Run retry scheduled, or the invocation paused because the run retry policy is exhausted.'
                        : 'Run retry scheduled.',
                    previous: $failure,
                );
            }

            // The policy is exhausted: the run's result is the terminal failure the core recorded from this one.
            $this->logRunFailure($name, $failure, "Run '{$name}' failed and its retry policy is exhausted; the failure is its result");

            return self::awaitRunCompletion($vm, $handle, $result);
        }

        $vm->proposeRunCompletionSuccess($handle, $this->encode($serde->serialize($value)), $policy);

        return self::awaitRunCompletion($vm, $handle, $result);
    }

    /**
     * A run callback failed with an error that is not terminal. The core reported it to Restate, but nothing else
     * would show it: the attempt ends as a suspension (or the failure becomes the run's result), so log it here. A run
     * only executes when it is not replayed, so this is logged once per execution.
     */
    private function logRunFailure(string $name, \Throwable $failure, string $message): void
    {
        SdkLog::write($this->endpointLogger, LogLevel::WARNING, $message, SdkLog::invocation($this->request) + [SdkLog::RUN_NAME => $name, 'exception' => $failure]);
    }

    /**
     * Awaits the acknowledgement of the proposed completion, then returns or throws the run's recorded result.
     *
     * @param ResultDecoder<mixed> $result
     */
    private static function awaitRunCompletion(InvocationOperations $vm, int $handle, ResultDecoder $result): mixed
    {
        ResultDecoder::assertCompleted($vm->doAwait($handle), 'ctx.run completion', 'Run completion suspended.');

        return $result->decode($vm->takeNotificationResult($handle));
    }

    private static function futureResult(InvocationOperations $operations, int $handle, Serde $serde, JournalValueCodec $codec, string $operation, string $suspensionMessage): DurableFuture
    {
        return DurableFutureImpl::of($operations, $handle, ResultDecoder::value($operation, $serde, $codec), $suspensionMessage);
    }

    /** Whether the attempt suspended; only a context on the endpoint's {@see SuspensionGuard} can tell. */
    private function isSuspended(): bool
    {
        return $this->vm instanceof SuspensionGuard && $this->vm->isSuspended();
    }

    protected function operations(): InvocationOperations
    {
        return $this->vm;
    }

    /** @param Serde|class-string<Serde>|null $serde */
    protected function serde(Serde|string|null $serde): Serde
    {
        if ($serde !== null) {
            return SerdeResolver::resolve($serde);
        }

        return SerdeResolver::resolveOrJson($this->defaultSerde);
    }

    protected function codec(): JournalValueCodec
    {
        return JournalValueCodecResolver::resolveOrNoop($this->journalValueCodec);
    }

    protected function encode(string $bytes): string
    {
        return $this->codec()->encode($bytes);
    }

    protected function decode(string $bytes): string
    {
        return $this->codec()->decode($bytes);
    }

    private function random(): DeterministicRandom
    {
        return $this->random ??= new DeterministicRandom($this->randomSeed);
    }
}
