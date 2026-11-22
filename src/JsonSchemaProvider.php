<?php

declare(strict_types=1);

namespace Restate;

interface JsonSchemaProvider
{
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata;
}
