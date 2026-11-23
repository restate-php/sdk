<?php

declare(strict_types=1);

namespace Restate;

use Restate\Reflection\Internal\JsonSchemaInferer;

final class BuiltinJsonSchemaProvider implements JsonSchemaProvider
{
    #[\Override]
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        return JsonSchemaInferer::metadataFor($context->type);
    }
}
