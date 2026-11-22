<?php

declare(strict_types=1);

namespace Restate\Reflection;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;
use Restate\Context;
use Restate\Payload;

final class HandlerPayloadInferer
{
    /**
     * @param callable|ReflectionFunctionAbstract $handler the handler callable, or the signature it is read from
     * @return array{0: Payload, 1: Payload, 2: ?\ReflectionType, 3: ?\ReflectionType}
     */
    public static function payloadsFor(string $handlerName, callable|ReflectionFunctionAbstract $handler, ?Payload $inputPayload, ?Payload $outputPayload): array
    {
        $reflection = $handler instanceof ReflectionFunctionAbstract ? $handler : self::reflectionFor($handler);
        self::assertContextFirst($handlerName, $reflection);

        $payloadParameters = array_slice($reflection->getParameters(), 1);
        if (count($payloadParameters) > 1) {
            throw new InvalidArgumentException("Handler {$handlerName} must declare at most one payload parameter after the context parameter.");
        }

        $inputParameter = $payloadParameters[0] ?? null;

        return [
            self::inputPayload($inputParameter, $inputPayload),
            self::outputPayload($reflection, $outputPayload),
            $inputParameter?->getType(),
            $reflection->getReturnType(),
        ];
    }

    private static function reflectionFor(callable $callback): ReflectionFunctionAbstract
    {
        return new ReflectionFunction(Closure::fromCallable($callback));
    }

    private static function assertContextFirst(string $handlerName, ReflectionFunctionAbstract $reflection): void
    {
        $parameters = $reflection->getParameters();
        if ($parameters === [] || !self::isContextParameter($parameters[0])) {
            throw new InvalidArgumentException("Handler {$handlerName} must declare a Restate context as the first parameter.");
        }
    }

    private static function isContextParameter(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        $name = $type->getName();

        return is_a($name, Context::class, true);
    }

    private static function inputPayload(?ReflectionParameter $parameter, ?Payload $explicitPayload): Payload
    {
        if ($parameter === null) {
            return $explicitPayload ?? Payload::empty();
        }

        return $explicitPayload ?? Payload::json();
    }

    private static function outputPayload(ReflectionFunctionAbstract $reflection, ?Payload $explicitPayload): Payload
    {
        $returnType = $reflection->getReturnType();
        if (JsonSchemaInferer::isEmptyOutput($returnType)) {
            return $explicitPayload ?? Payload::empty();
        }

        return $explicitPayload ?? Payload::json();
    }
}
