<?php

declare(strict_types=1);

namespace Restate;

use ReflectionType;

final readonly class JsonSchemaContext
{
    public function __construct(
        public readonly ?ReflectionType $type,
        public readonly PayloadRole $role,
        public readonly string $serviceName,
        public readonly string $handlerName,
    ) {
    }
}
