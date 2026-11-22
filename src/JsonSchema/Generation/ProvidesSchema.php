<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Generation;

use Restate\JsonSchema\Schema;

interface ProvidesSchema
{
    public static function jsonSchema(SchemaGenerator $generator): Schema;
}
