<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use JsonSerializable;

interface Schema extends JsonSerializable
{
    public function toArray(): mixed;

    public function toJson(): string;

    public function jsonSerialize(): mixed;
}
