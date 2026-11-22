<?php

declare(strict_types=1);

namespace Restate\Internal;

use InvalidArgumentException;
use Restate\JsonSerde;
use Restate\Serde;

/**
 * Resolves a serde given as an instance or a class name.
 *
 * @internal
 */
final class SerdeResolver
{
    /** @param Serde|class-string<Serde> $serde */
    public static function resolve(Serde|string $serde): Serde
    {
        if ($serde instanceof Serde) {
            return $serde;
        }

        if (!class_exists($serde)) {
            throw new InvalidArgumentException("Serde class {$serde} does not exist.");
        }
        if (!is_subclass_of($serde, Serde::class)) {
            throw new InvalidArgumentException("Serde class {$serde} must implement " . Serde::class . '.');
        }

        $reflection = new \ReflectionClass($serde);
        $constructor = $reflection->getConstructor();
        if (!$reflection->isInstantiable() || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            throw new InvalidArgumentException("Serde class {$serde} must be instantiable without constructor arguments.");
        }

        $instance = $reflection->newInstance();
        return $instance;
    }

    /** @param Serde|class-string<Serde>|null $serde */
    public static function resolveOrJson(Serde|string|null $serde): Serde
    {
        return $serde === null ? JsonSerde::default() : self::resolve($serde);
    }
}
