<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;
use Restate\Internal\ClosureErrorMapper;
use Restate\Internal\SerdeResolver;
use Restate\Internal\ServiceDefinitionConstruction;

final readonly class Workflow
{
    /**
     * @param list<HandlerDefinition> $handlers
     * @param array<array-key, mixed> $metadata
     * @param list<HandlerInterceptor|RunInterceptor> $interceptors
     */
    private function __construct(
        private readonly string $name,
        private readonly array $handlers = [],
        private readonly ?string $documentation = null,
        private readonly array $metadata = [],
        private readonly ?InvocationRetryPolicy $invocationRetryPolicy = null,
        private readonly ?Serde $defaultSerde = null,
        private readonly ?InvocationConfiguration $invocationConfiguration = null,
        private readonly array $interceptors = [],
        private readonly ?ErrorMapper $errorMapper = null,
    ) {
        ServiceDefinitionConstruction::assertValidServiceName($name);
    }

    /** @param array<array-key, mixed> $metadata */
    public static function new(string $name, ?string $documentation = null, array $metadata = [], ?InvocationRetryPolicy $invocationRetryPolicy = null, ?InvocationConfiguration $invocationConfiguration = null): self
    {
        return new self($name, documentation: $documentation, metadata: $metadata, invocationRetryPolicy: $invocationRetryPolicy, invocationConfiguration: $invocationConfiguration);
    }

    /** @param array<array-key, mixed> $metadata */
    public function run(callable $callback, ?Payload $input = null, ?Payload $output = null, ?string $documentation = null, array $metadata = [], ?InvocationRetryPolicy $invocationRetryPolicy = null, ?InvocationConfiguration $invocationConfiguration = null): self
    {
        return $this->addHandler('run', $callback, HandlerDefinition::TYPE_WORKFLOW, $input, $output, $documentation, $metadata, $invocationRetryPolicy, $invocationConfiguration);
    }

    /** @param array<array-key, mixed> $metadata */
    public function sharedHandler(string $name, callable $callback, ?Payload $input = null, ?Payload $output = null, ?string $documentation = null, array $metadata = [], ?InvocationRetryPolicy $invocationRetryPolicy = null, ?InvocationConfiguration $invocationConfiguration = null): self
    {
        return $this->addHandler($name, $callback, HandlerDefinition::TYPE_SHARED, $input, $output, $documentation, $metadata, $invocationRetryPolicy, $invocationConfiguration);
    }

    public function definition(): ServiceDefinition
    {
        return new ServiceDefinition($this->name, $this->handlers, ServiceDefinition::TYPE_WORKFLOW, $this->documentation, $this->metadata, $this->invocationRetryPolicy, $this->defaultSerde, $this->invocationConfiguration, $this->interceptors, $this->errorMapper);
    }

    /** @param Serde|class-string<Serde> $serde */
    public function defaultSerde(Serde|string $serde): self
    {
        return new self($this->name, $this->handlers, $this->documentation, $this->metadata, $this->invocationRetryPolicy, SerdeResolver::resolve($serde), $this->invocationConfiguration, $this->interceptors, $this->errorMapper);
    }

    /**
     * Adds Runtime Execution Hooks for this workflow's handlers, inside the endpoint's and after the ones added before:
     * the first one added is the outermost. An object implementing both interfaces wraps handlers and runs.
     */
    public function interceptor(HandlerInterceptor|RunInterceptor ...$interceptors): self
    {
        return new self($this->name, $this->handlers, $this->documentation, $this->metadata, $this->invocationRetryPolicy, $this->defaultSerde, $this->invocationConfiguration, [...$this->interceptors, ...array_values($interceptors)], $this->errorMapper);
    }

    /**
     * Sets the error mapper for this workflow's handlers and runs, asked before the endpoint's.
     *
     * @param ErrorMapper|\Closure(\Throwable): (TerminalError|RetryableError|PauseError|null) $errorMapper
     */
    public function errorMapper(ErrorMapper|\Closure $errorMapper): self
    {
        return new self($this->name, $this->handlers, $this->documentation, $this->metadata, $this->invocationRetryPolicy, $this->defaultSerde, $this->invocationConfiguration, $this->interceptors, ClosureErrorMapper::of($errorMapper));
    }

    public function invocationConfiguration(InvocationConfiguration $invocationConfiguration): self
    {
        return new self($this->name, $this->handlers, $this->documentation, $this->metadata, $this->invocationRetryPolicy, $this->defaultSerde, $invocationConfiguration, $this->interceptors, $this->errorMapper);
    }

    /** @param array<array-key, mixed> $metadata */
    private function addHandler(string $name, callable $callback, string $type, ?Payload $input = null, ?Payload $output = null, ?string $documentation = null, array $metadata = [], ?InvocationRetryPolicy $invocationRetryPolicy = null, ?InvocationConfiguration $invocationConfiguration = null): self
    {
        ServiceDefinitionConstruction::assertValidHandlerName($name);
        ServiceDefinitionConstruction::assertWorkflowHandlerNameNotReserved($name);

        if (array_any($this->handlers, static fn (HandlerDefinition $handler): bool => $handler->name === $name)) {
            throw new InvalidArgumentException("Handler {$this->name}/{$name} is already registered.");
        }

        return new self(
            $this->name,
            [...$this->handlers, new HandlerDefinition($name, $callback, $type, $input, $output, $documentation, $metadata, $invocationRetryPolicy, $invocationConfiguration)],
            $this->documentation,
            $this->metadata,
            $this->invocationRetryPolicy,
            $this->defaultSerde,
            $this->invocationConfiguration,
            $this->interceptors,
            $this->errorMapper,
        );
    }
}
