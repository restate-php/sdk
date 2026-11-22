<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Generation;

use InvalidArgumentException;

final class UnsupportedJsonSchemaTypeException extends InvalidArgumentException
{
    public static function forProperty(string $className, string $propertyName, string $type): self
    {
        return new self("DTO {$className} property {$propertyName} has unsupported JSON Schema type {$type}.");
    }
}
