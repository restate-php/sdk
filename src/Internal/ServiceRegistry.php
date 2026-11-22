<?php

declare(strict_types=1);

namespace Restate\Internal;

use InvalidArgumentException;
use Restate\Internal\Context\ContextImpl;
use Restate\Internal\Context\ObjectContextImpl;
use Restate\Internal\Context\ObjectSharedContextImpl;
use Restate\Internal\Context\WorkflowContextImpl;
use Restate\Internal\Context\WorkflowSharedContextImpl;
use Restate\Context;
use Restate\ErrorMapper;
use Restate\HandlerExecution;
use Restate\HandlerInterceptor;
use Restate\HandlerDefinition;
use Restate\JournalValueCodec;
use Restate\JsonPayloadOptions;
use Restate\Request;
use Restate\RunInterceptor;
use Restate\Serde;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\Target;
use Restate\TerminalError;
use Restate\VirtualObject;
use Restate\Workflow;

/**
 * An endpoint's services by name: builds the handler context for each invocation and invokes the handler.
 *
 * @internal
 */
final class ServiceRegistry
{
    /** @var array<string, ServiceDefinition> */
    private array $services = [];

    /**
     * Each registered handler with its Effective Handler Payloads, resolved once when the registry is built.
     *
     * @var array<string, array<string, array{handler: HandlerDefinition, input: EffectiveHandlerPayload, output: EffectiveHandlerPayload}>>
     */
    private array $handlers = [];

    private readonly JsonPayloadOptions $jsonPayloads;

    private readonly Serde $defaultSerde;

    private readonly JournalValueCodec $journalValueCodec;

    /** @var array<string, ExecutionHooks> each service's hooks, the endpoint's around its own */
    private array $hooks = [];

    /**
     * @param iterable<mixed> $services
     * @param Serde|class-string<Serde>|null $defaultSerde
     * @param JournalValueCodec|class-string<JournalValueCodec>|null $journalValueCodec
     * @param list<HandlerInterceptor|RunInterceptor> $interceptors the endpoint's
     */
    public function __construct(iterable $services = [], ?JsonPayloadOptions $jsonPayloads = null, Serde|string|null $defaultSerde = null, JournalValueCodec|string|null $journalValueCodec = null, array $interceptors = [], ?ErrorMapper $errorMapper = null)
    {
        $endpointHooks = ExecutionHooks::of(ServiceDefinitionConstruction::validatedInterceptors($interceptors), $errorMapper);
        $this->jsonPayloads = $jsonPayloads ?? JsonPayloadOptions::defaults();
        $this->defaultSerde = SerdeResolver::resolveOrJson($defaultSerde);
        $this->journalValueCodec = JournalValueCodecResolver::resolveOrNoop($journalValueCodec);
        foreach ($services as $service) {
            $definition = $service instanceof Service || $service instanceof VirtualObject || $service instanceof Workflow ? $service->definition() : $service;
            if (!$definition instanceof ServiceDefinition) {
                throw new InvalidArgumentException('ServiceRegistry accepts only Service, VirtualObject, Workflow, or ServiceDefinition instances.');
            }
            if (isset($this->services[$definition->name])) {
                throw new InvalidArgumentException("Service {$definition->name} is already registered.");
            }
            $this->services[$definition->name] = $definition;
            $this->hooks[$definition->name] = $endpointHooks->around(ExecutionHooks::of($definition->interceptors, $definition->errorMapper));
            foreach ($definition->handlers as $handler) {
                $this->handlers[$definition->name][$handler->name] = [
                    'handler' => $handler,
                    'input' => EffectiveHandlerPayload::input($definition, $handler, $this->defaultSerde, $this->jsonPayloads),
                    'output' => EffectiveHandlerPayload::output($definition, $handler, $this->defaultSerde, $this->jsonPayloads),
                ];
            }
        }
    }

    /** @return list<ServiceDefinition> */
    public function services(): array
    {
        return array_values($this->services);
    }

    public function defaultSerde(): Serde
    {
        return $this->defaultSerde;
    }

    public function journalValueCodec(): JournalValueCodec
    {
        return $this->journalValueCodec;
    }

    public function jsonPayloads(): JsonPayloadOptions
    {
        return $this->jsonPayloads;
    }

    /**
     * Runs a handler on a context built from the attempt's operations and request data.
     */
    public function invoke(string $service, string $handler, string $input, InvocationAttempt $attempt, ?string $key = null): string
    {
        $definition = $this->services[$service] ?? null;
        if ($definition === null) {
            throw new TerminalError("Unknown service {$service}", 404);
        }

        $resolved = $this->handlers[$service][$handler] ?? null;
        if ($resolved === null) {
            throw new TerminalError("Unknown handler {$service}/{$handler}", 404);
        }

        $hooks = $this->hooks[$service];
        $handlerContext = $this->contextForHandler($definition, $resolved['handler'], $attempt, $input, $key, $hooks);
        $operations = $attempt->operations;
        $isSuspended = static fn (): bool => $operations instanceof SuspensionGuard && $operations->isSuspended();

        try {
            return $hooks->executeHandler(
                new HandlerExecution($handlerContext->request(), $handlerContext->isReplaying(...)),
                $isSuspended,
                function () use ($resolved, $handlerContext, $input): string {
                    $decoded = $resolved['input']->decodeInput($input, $this->journalValueCodec);
                    $result = ($resolved['handler']->callback)($handlerContext, $decoded);

                    return $resolved['output']->encodeOutput($result, $this->journalValueCodec);
                },
            );
        } catch (\Throwable $e) {
            if ($isSuspended()) {
                throw $e;
            }

            throw $hooks->mapError($e) ?? $e;
        }
    }

    private function contextForHandler(ServiceDefinition $service, HandlerDefinition $handler, InvocationAttempt $attempt, string $input, ?string $key, ExecutionHooks $hooks): Context
    {
        $operations = $attempt->operations;
        $defaultSerde = $this->defaultSerdeFor($service);

        if ($service->type === ServiceDefinition::TYPE_SERVICE) {
            $request = self::request($attempt, Target::service($service->name, $handler->name), $input);

            return new ContextImpl($operations, $request, $attempt->randomSeed, $defaultSerde, $this->journalValueCodec, $hooks, $attempt->logger);
        }

        if ($key === null || $key === '') {
            throw new TerminalError(match ($service->type) {
                ServiceDefinition::TYPE_VIRTUAL_OBJECT => 'Virtual Object invocation key is required',
                ServiceDefinition::TYPE_WORKFLOW => 'Workflow invocation key is required',
                default => 'Invocation key is required',
            }, 400);
        }

        $target = $service->type === ServiceDefinition::TYPE_WORKFLOW
            ? Target::workflow($service->name, $key, $handler->name)
            : Target::object($service->name, $key, $handler->name);
        $request = self::request($attempt, $target, $input);

        return match ($service->type) {
            ServiceDefinition::TYPE_VIRTUAL_OBJECT => match ($handler->type) {
                HandlerDefinition::TYPE_EXCLUSIVE => new ObjectContextImpl($operations, $request, $attempt->randomSeed, $defaultSerde, $this->journalValueCodec, $hooks, $attempt->logger),
                HandlerDefinition::TYPE_SHARED => new ObjectSharedContextImpl($operations, $request, $attempt->randomSeed, $defaultSerde, $this->journalValueCodec, $hooks, $attempt->logger),
                default => throw new TerminalError('Virtual Object handler type is required', 500),
            },
            ServiceDefinition::TYPE_WORKFLOW => match ($handler->type) {
                HandlerDefinition::TYPE_WORKFLOW => new WorkflowContextImpl($operations, $request, $attempt->randomSeed, $defaultSerde, $this->journalValueCodec, $hooks, $attempt->logger),
                HandlerDefinition::TYPE_SHARED => new WorkflowSharedContextImpl($operations, $request, $attempt->randomSeed, $defaultSerde, $this->journalValueCodec, $hooks, $attempt->logger),
                default => throw new TerminalError('Workflow handler type is required', 500),
            },
            default => throw new TerminalError('Service type is unsupported', 500),
        };
    }

    private static function request(InvocationAttempt $attempt, Target $target, string $input): Request
    {
        return new Request(
            $attempt->invocationId,
            $target,
            self::headerMap($attempt->headers),
            self::headerMap($attempt->attemptHeaders),
            $input,
            $attempt->idempotencyKey,
            $attempt->scope,
            $attempt->limitKey,
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @return array<string, string>
     */
    private static function headerMap(array $pairs): array
    {
        $headers = [];
        foreach ($pairs as [$name, $value]) {
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    /**
     * The handler's input payload as the registry resolved it. With other JSON payload options (a direct
     * Discovery::manifestJson() override) it is resolved again for those options.
     */
    public function effectiveHandlerInputPayload(ServiceDefinition $service, HandlerDefinition $handler, ?JsonPayloadOptions $jsonPayloads = null): EffectiveHandlerPayload
    {
        $resolved = $this->resolved($service, $handler);
        if ($jsonPayloads === null || $jsonPayloads === $this->jsonPayloads) {
            return $resolved['input'];
        }

        return EffectiveHandlerPayload::input($service, $handler, $this->defaultSerde, $jsonPayloads);
    }

    /**
     * The handler's output payload as the registry resolved it. With other JSON payload options (a direct
     * Discovery::manifestJson() override) it is resolved again for those options.
     */
    public function effectiveHandlerOutputPayload(ServiceDefinition $service, HandlerDefinition $handler, ?JsonPayloadOptions $jsonPayloads = null): EffectiveHandlerPayload
    {
        $resolved = $this->resolved($service, $handler);
        if ($jsonPayloads === null || $jsonPayloads === $this->jsonPayloads) {
            return $resolved['output'];
        }

        return EffectiveHandlerPayload::output($service, $handler, $this->defaultSerde, $jsonPayloads);
    }

    /** @return array{handler: HandlerDefinition, input: EffectiveHandlerPayload, output: EffectiveHandlerPayload} */
    private function resolved(ServiceDefinition $service, HandlerDefinition $handler): array
    {
        $resolved = $this->handlers[$service->name][$handler->name] ?? null;
        if ($resolved === null || ($this->services[$service->name] ?? null) !== $service || $resolved['handler'] !== $handler) {
            throw new InvalidArgumentException("Handler {$service->name}/{$handler->name} is not registered in this ServiceRegistry.");
        }

        return $resolved;
    }

    private function defaultSerdeFor(ServiceDefinition $service): Serde
    {
        return $service->defaultSerde ?? $this->defaultSerde;
    }
}
