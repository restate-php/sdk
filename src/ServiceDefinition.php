<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;
use Restate\Internal\ClosureErrorMapper;
use Restate\Internal\ServiceDefinitionConstruction;

final readonly class ServiceDefinition
{
    /** @var array<string, string> */
    public readonly array $metadata;

    /**
     * The service's Runtime Execution Hooks, outermost first; they run inside the endpoint's.
     *
     * @var list<HandlerInterceptor|RunInterceptor>
     */
    public readonly array $interceptors;

    /** Asked before the endpoint's error mapper. */
    public readonly ?ErrorMapper $errorMapper;

    /**
     * @param list<HandlerDefinition> $handlers
     * @param array<array-key, mixed> $metadata
     * @param list<HandlerInterceptor|RunInterceptor> $interceptors
     * @param ErrorMapper|\Closure(\Throwable): (TerminalError|RetryableError|PauseError|null)|null $errorMapper
     */
    public function __construct(
        public readonly string $name,
        public readonly array $handlers,
        public readonly ServiceType $type = ServiceType::Service,
        public readonly ?string $documentation = null,
        array $metadata = [],
        public readonly ?InvocationRetryPolicy $invocationRetryPolicy = null,
        public readonly ?Serde $defaultSerde = null,
        public readonly ?InvocationConfiguration $invocationConfiguration = null,
        array $interceptors = [],
        ErrorMapper|\Closure|null $errorMapper = null,
    ) {
        ServiceDefinitionConstruction::assertValidServiceName($name);
        ServiceDefinitionConstruction::assertHandlers($handlers);
        ServiceDefinitionConstruction::assertStatePreloadAllowed($name, $type, $invocationConfiguration);

        $handlerNames = [];
        $hasWorkflowRun = false;
        foreach ($handlers as $handler) {
            if (isset($handlerNames[$handler->name])) {
                throw new InvalidArgumentException("Handler {$name}/{$handler->name} is already registered.");
            }
            $handlerNames[$handler->name] = true;

            ServiceDefinitionConstruction::assertHandlerCompatible($name, $type, $handler);
            $hasWorkflowRun = $hasWorkflowRun || ($handler->name === 'run' && $handler->type === HandlerType::Workflow);
        }

        if ($type === ServiceType::Workflow && !$hasWorkflowRun) {
            throw new InvalidArgumentException("Workflow {$name} must define a run handler.");
        }

        $this->metadata = ServiceDefinitionConstruction::validatedMetadata($metadata, 'Service');
        $this->interceptors = ServiceDefinitionConstruction::validatedInterceptors($interceptors);
        $this->errorMapper = $errorMapper === null ? null : ClosureErrorMapper::of($errorMapper);
    }

    /** A copy with $interceptors added inside the ones it has (for a service reflected from its attributes). */
    public function withInterceptors(HandlerInterceptor|RunInterceptor ...$interceptors): self
    {
        return new self($this->name, $this->handlers, $this->type, $this->documentation, $this->metadata, $this->invocationRetryPolicy, $this->defaultSerde, $this->invocationConfiguration, [...$this->interceptors, ...array_values($interceptors)], $this->errorMapper);
    }

    /**
     * A copy with this error mapper instead of the one it has.
     *
     * @param ErrorMapper|\Closure(\Throwable): (TerminalError|RetryableError|PauseError|null) $errorMapper
     */
    public function withErrorMapper(ErrorMapper|\Closure $errorMapper): self
    {
        return new self($this->name, $this->handlers, $this->type, $this->documentation, $this->metadata, $this->invocationRetryPolicy, $this->defaultSerde, $this->invocationConfiguration, $this->interceptors, $errorMapper);
    }
}
