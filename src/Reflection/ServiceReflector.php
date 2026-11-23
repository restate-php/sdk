<?php

declare(strict_types=1);

namespace Restate\Reflection;

use InvalidArgumentException;
use ReflectionClass;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Restate\Attributes\Handler;
use Restate\Attributes\Input;
use Restate\Attributes\Output;
use Restate\Attributes\Shared;
use Restate\Attributes\Service as ServiceAttribute;
use Restate\Attributes\VirtualObject as VirtualObjectAttribute;
use Restate\Attributes\Workflow as WorkflowAttribute;
use Restate\Context;
use Restate\HandlerDefinition;
use Restate\HandlerType;
use Restate\Internal\ServiceDefinitionConstruction;
use Restate\Request;
use Restate\ServiceDefinition;
use Restate\ServiceType;

final class ServiceReflector
{
    /**
     * The service definition of an attributed service object; every invocation calls its handler methods on $instance.
     */
    public static function fromObject(object $instance): ServiceDefinition
    {
        return self::define(new ReflectionClass($instance), static fn (ReflectionMethod $method): array => [$method->getClosure($instance), null]);
    }

    /**
     * The service definition of an attributed service class whose instances are created per invocation attempt, right
     * before the handler runs (inside the handler interceptors): by `$instances($request)` when it is a callable, which
     * gets the invocation's {@see Request}, or by `$instances->get($class)` when it is a PSR-11 container (whether that
     * returns a new object is the container's configuration).
     *
     * The definition (handler names, kinds, payloads, the context check) is read from the class by reflection; nothing
     * is instantiated to build it or to answer discovery. A container that does not have `$class` is rejected here.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param ContainerInterface|callable(Request): T $instances
     */
    public static function fromClass(string $class, ContainerInterface|callable $instances): ServiceDefinition
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Service class {$class} does not exist.");
        }

        if ($instances instanceof ContainerInterface) {
            if (!$instances->has($class)) {
                throw new InvalidArgumentException("The container has no entry for service class {$class}.");
            }

            $container = $instances;
            $instances = static fn (Request $request): mixed => $container->get($class);
        }

        $factory = \Closure::fromCallable($instances);
        $instance = static function (Request $request) use ($factory, $class): object {
            $instance = $factory($request);
            if (!$instance instanceof $class) {
                throw new \UnexpectedValueException(sprintf('The factory of service class %s returned %s.', $class, get_debug_type($instance)));
            }

            return $instance;
        };

        return self::define(new ReflectionClass($class), static fn (ReflectionMethod $method): array => [
            static fn (Context $ctx, mixed $input = null): mixed => $method->invoke($instance($ctx->request()), $ctx, $input),
            $method,
        ]);
    }

    /**
     * @param ReflectionClass<object> $class
     * @param \Closure(ReflectionMethod): array{0: callable, 1: ?ReflectionMethod} $binding a handler method's callback,
     *                                                                                    and its signature when the
     *                                                                                    callback only forwards to it
     */
    private static function define(ReflectionClass $class, \Closure $binding): ServiceDefinition
    {
        $serviceAttributes = $class->getAttributes(ServiceAttribute::class);
        $objectAttributes = $class->getAttributes(VirtualObjectAttribute::class);
        $workflowAttributes = $class->getAttributes(WorkflowAttribute::class);

        if (count($serviceAttributes) + count($objectAttributes) + count($workflowAttributes) !== 1) {
            throw new InvalidArgumentException('Service class must have exactly one Restate service type attribute.');
        }

        if (count($serviceAttributes) === 1) {
            $attribute = $serviceAttributes[0]->newInstance();
            $type = ServiceType::Service;
        } elseif (count($objectAttributes) === 1) {
            $attribute = $objectAttributes[0]->newInstance();
            $type = ServiceType::VirtualObject;
        } else {
            $attribute = $workflowAttributes[0]->newInstance();
            $type = ServiceType::Workflow;
        }

        ServiceDefinitionConstruction::assertValidServiceName($attribute->name);

        $handlers = [];
        $handlerNames = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $handlerAttributes = $method->getAttributes(Handler::class);
            if ($handlerAttributes === []) {
                continue;
            }

            if (count($handlerAttributes) !== 1) {
                throw new InvalidArgumentException("Handler method {$method->getName()} must have exactly one Handler attribute.");
            }

            $handlerAttribute = $handlerAttributes[0]->newInstance();
            $handlerName = $handlerAttribute->name ?? $method->getName();
            ServiceDefinitionConstruction::assertValidHandlerName($handlerName);
            if (isset($handlerNames[$handlerName])) {
                throw new InvalidArgumentException("Handler {$attribute->name}/{$handlerName} is already registered.");
            }
            $handlerNames[$handlerName] = true;

            $sharedAttributes = $method->getAttributes(Shared::class);
            $handlerType = null;
            if ($type === ServiceType::VirtualObject) {
                $handlerType = $sharedAttributes === []
                    ? HandlerType::Exclusive
                    : HandlerType::Shared;
            } elseif ($type === ServiceType::Workflow) {
                if ($handlerName === 'run') {
                    if ($sharedAttributes !== []) {
                        throw new InvalidArgumentException("Workflow {$attribute->name}/run handler must not be shared.");
                    }
                    $handlerType = HandlerType::Workflow;
                } else {
                    ServiceDefinitionConstruction::assertWorkflowHandlerNameNotReserved($handlerName);
                    if ($sharedAttributes === []) {
                        throw new InvalidArgumentException("Workflow {$attribute->name}/{$handlerName} handler must be shared.");
                    }
                    $handlerType = HandlerType::Shared;
                }
            }

            $inputAttributes = $method->getAttributes(Input::class);
            if (count($inputAttributes) > 1) {
                throw new InvalidArgumentException("Handler method {$method->getName()} must have at most one Input attribute.");
            }

            $outputAttributes = $method->getAttributes(Output::class);
            if (count($outputAttributes) > 1) {
                throw new InvalidArgumentException("Handler method {$method->getName()} must have at most one Output attribute.");
            }

            $inputPayload = $inputAttributes === [] ? null : $inputAttributes[0]->newInstance()->payload();
            $outputPayload = $outputAttributes === [] ? null : $outputAttributes[0]->newInstance()->payload();

            [$callback, $signature] = $binding($method);
            $handlers[] = new HandlerDefinition(
                $handlerName,
                $callback,
                $handlerType,
                $inputPayload,
                $outputPayload,
                documentation: $handlerAttribute->documentation,
                metadata: $handlerAttribute->metadata,
                invocationRetryPolicy: $handlerAttribute->invocationRetryPolicy,
                invocationConfiguration: $handlerAttribute->invocationConfiguration,
                signature: $signature,
            );
        }

        return new ServiceDefinition(
            $attribute->name,
            $handlers,
            $type,
            documentation: $attribute->documentation,
            metadata: $attribute->metadata,
            invocationRetryPolicy: $attribute->invocationRetryPolicy,
            invocationConfiguration: $attribute->invocationConfiguration,
        );
    }
}
