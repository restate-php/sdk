<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

final class BooleanSchema implements Schema
{
    private function __construct(
        private readonly bool $value,
    ) {
    }

    public static function true(): self
    {
        return new self(true);
    }

    public static function false(): self
    {
        return new self(false);
    }

    public function property(string $name): PropertySchema
    {
        return PropertySchema::new($name, $this);
    }

    #[\Override]
    public function toArray(): bool
    {
        return $this->value;
    }

    #[\Override]
    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    #[\Override]
    public function jsonSerialize(): bool
    {
        return $this->value;
    }
}
