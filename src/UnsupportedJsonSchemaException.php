<?php

declare(strict_types=1);

namespace Restate;

final class UnsupportedJsonSchemaException extends \RuntimeException implements Exception
{
    public static function forContext(JsonSchemaContext $context): self
    {
        return new self("JSON Schema Metadata is unsupported for {$context->serviceName}/{$context->handlerName} {$context->role->value}.");
    }
}
