<?php

declare(strict_types=1);

namespace Restate\Internal;

use InvalidArgumentException;
use ReflectionNamedType;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\HandlerType;
use Restate\HandlerInterceptor;
use Restate\InvocationConfiguration;
use Restate\RunInterceptor;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\ServiceType;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

/**
 * Validates the services and handlers a service definition is built from.
 *
 * @internal
 */
final class ServiceDefinitionConstruction
{
    private const array RESERVED_WORKFLOW_HANDLER_NAMES = [
        'workflowSubmit',
        'workflowAttach',
        'workflowOutput',
    ];

    public static function assertValidServiceName(string $name): void
    {
        if (preg_match('/^([a-zA-Z]|_[a-zA-Z0-9])[a-zA-Z0-9._-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid Restate service name {$name}.");
        }
    }

    public static function assertValidHandlerName(string $name): void
    {
        if (preg_match('/^([a-zA-Z]|_[a-zA-Z0-9])[a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid Restate handler name {$name}.");
        }
    }

    /** @param array<array-key, mixed> $handlers */
    public static function assertHandlers(array $handlers): void
    {
        foreach ($handlers as $handler) {
            if (!$handler instanceof HandlerDefinition) {
                throw new InvalidArgumentException('Service handlers must be HandlerDefinition instances.');
            }
        }
    }

    public static function assertHandlerCompatible(string $serviceName, ServiceType $serviceType, HandlerDefinition $handler): void
    {
        self::assertHandlerType($serviceName, $serviceType, $handler);
        self::assertContextReceived($serviceName, $serviceType, $handler);
        self::assertStatePreloadAllowed("{$serviceName}/{$handler->name}", $serviceType, $handler->invocationConfiguration);
    }

    /** Only virtual objects and workflows have state, so only they can choose what of it is preloaded. */
    public static function assertStatePreloadAllowed(string $subject, ServiceType $serviceType, ?InvocationConfiguration $configuration): void
    {
        if ($serviceType === ServiceType::Service && $configuration?->statePreload !== null) {
            throw new InvalidArgumentException("Service {$subject} has no state: state preloading applies only to virtual objects and workflows.");
        }
    }

    private static function assertHandlerType(string $serviceName, ServiceType $serviceType, HandlerDefinition $handler): void
    {
        if ($serviceType === ServiceType::Service) {
            if ($handler->type !== null) {
                throw new InvalidArgumentException("Service {$serviceName}/{$handler->name} handler type must not be set.");
            }

            return;
        }

        if ($serviceType === ServiceType::VirtualObject) {
            if (!in_array($handler->type, [HandlerType::Exclusive, HandlerType::Shared], true)) {
                throw new InvalidArgumentException("Virtual Object {$serviceName}/{$handler->name} handler type must be EXCLUSIVE or SHARED.");
            }

            return;
        }

        self::assertWorkflowHandlerNameNotReserved($handler->name);

        if ($handler->name === 'run') {
            if ($handler->type !== HandlerType::Workflow) {
                throw new InvalidArgumentException("Workflow {$serviceName}/run handler must be a workflow handler.");
            }

            return;
        }

        if ($handler->type !== HandlerType::Shared) {
            throw new InvalidArgumentException("Workflow {$serviceName}/{$handler->name} handler must be shared.");
        }
    }

    /**
     * The handler's context parameter must accept the context its kind receives: that context or one it extends. A
     * narrower type (or an implementation class) would fail with a TypeError on every invocation.
     */
    private static function assertContextReceived(string $serviceName, ServiceType $serviceType, HandlerDefinition $handler): void
    {
        $received = self::contextFor($serviceType, $handler->type);

        // The handler definition already checked that the first parameter declares a context type.
        $type = $handler->signature()->getParameters()[0]->getType();
        if (!$type instanceof ReflectionNamedType) {
            return;
        }

        $declared = $type->getName();
        if (!is_a($received, $declared, true)) {
            $owner = match ($serviceType) {
                ServiceType::VirtualObject => 'Virtual Object',
                ServiceType::Workflow => 'Workflow',
                ServiceType::Service => 'Service',
            };

            throw new InvalidArgumentException("{$owner} {$serviceName}/{$handler->name} handler declares context {$declared}, but receives a {$received}.");
        }
    }

    /**
     * The context interface the service registry gives a handler of this kind.
     *
     * @return class-string<Context>
     */
    private static function contextFor(ServiceType $serviceType, ?HandlerType $handlerType): string
    {
        return match ($serviceType) {
            ServiceType::VirtualObject => $handlerType === HandlerType::Shared ? ObjectSharedContext::class : ObjectContext::class,
            ServiceType::Workflow => $handlerType === HandlerType::Shared ? WorkflowSharedContext::class : WorkflowContext::class,
            ServiceType::Service => Context::class,
        };
    }

    public static function assertWorkflowHandlerNameNotReserved(string $name): void
    {
        if (in_array($name, self::RESERVED_WORKFLOW_HANDLER_NAMES, true)) {
            throw new InvalidArgumentException("Workflow handler {$name} is reserved by Restate.");
        }
    }

    /**
     * @param array<array-key, mixed> $interceptors
     * @return list<HandlerInterceptor|RunInterceptor>
     */
    public static function validatedInterceptors(array $interceptors): array
    {
        $validated = [];
        foreach ($interceptors as $interceptor) {
            if (!$interceptor instanceof HandlerInterceptor && !$interceptor instanceof RunInterceptor) {
                throw new InvalidArgumentException(sprintf('Interceptors must implement HandlerInterceptor or RunInterceptor, got %s.', get_debug_type($interceptor)));
            }

            $validated[] = $interceptor;
        }

        return $validated;
    }

    /**
     * @param array<array-key, mixed> $metadata
     * @return array<string, string>
     */
    public static function validatedMetadata(array $metadata, string $owner): array
    {
        $validated = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException("{$owner} metadata keys must be strings.");
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException("{$owner} metadata values must be strings.");
            }

            $validated[$key] = $value;
        }

        return $validated;
    }
}
