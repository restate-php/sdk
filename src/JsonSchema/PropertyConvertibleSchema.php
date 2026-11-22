<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

/** @internal */
trait PropertyConvertibleSchema
{
    public function property(string $name): PropertySchema
    {
        return PropertySchema::new($name, $this);
    }
}
