<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Generation;

use InvalidArgumentException;
use Restate\Exception;

/** A DTO property whose type DTO JSON Schema Generation cannot describe. */
final class UnsupportedJsonSchemaTypeException extends InvalidArgumentException implements Exception
{
    public static function forProperty(string $className, string $propertyName, string $type): self
    {
        return new self("DTO {$className} property {$propertyName} has unsupported JSON Schema type {$type}.");
    }
}
