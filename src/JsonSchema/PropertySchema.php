<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;

final class PropertySchema implements Schema
{
    private function __construct(
        private readonly string $name,
        private readonly Schema $schema,
        private readonly bool $required,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('JSON Schema property name must not be empty.');
        }
    }

    public static function new(string $name, Schema $schema): self
    {
        return new self($name, $schema, false);
    }

    public function required(): self
    {
        return new self($this->name, $this->schema, true);
    }

    public function optional(): self
    {
        return new self($this->name, $this->schema, false);
    }

    public function propertyName(): string
    {
        return $this->name;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    #[\Override]
    public function toArray(): mixed
    {
        return $this->schema->toArray();
    }

    #[\Override]
    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->schema->jsonSerialize();
    }
}
