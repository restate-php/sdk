<?php

declare(strict_types=1);

namespace Restate;

final class JsonPayloadOptions
{
    private ?JsonValueMapper $valueMapper = null;

    private ?JsonSchemaProvider $schemaProvider = null;

    public static function new(): self
    {
        return new self();
    }

    public static function defaults(): self
    {
        return new self()->withSchemaProvider(new BuiltinJsonSchemaProvider());
    }

    public function withValueMapper(JsonValueMapper $valueMapper): self
    {
        $clone = clone $this;
        $clone->valueMapper = $valueMapper;

        return $clone;
    }

    public function withSchemaProvider(JsonSchemaProvider $schemaProvider): self
    {
        $clone = clone $this;
        $clone->schemaProvider = $schemaProvider;

        return $clone;
    }

    public function withSchemaProviders(JsonSchemaProvider $schemaProvider, JsonSchemaProvider ...$fallbackSchemaProviders): self
    {
        return $this->withSchemaProvider(
            $fallbackSchemaProviders === []
                ? $schemaProvider
                : new FallbackJsonSchemaProvider($schemaProvider, ...$fallbackSchemaProviders),
        );
    }

    public function withPayloadAdapter(JsonValueMapper&JsonSchemaProvider $adapter): self
    {
        $clone = clone $this;
        $clone->valueMapper = $adapter;
        $clone->schemaProvider = $adapter;

        return $clone;
    }

    public function valueMapper(): ?JsonValueMapper
    {
        return $this->valueMapper;
    }

    public function schemaProvider(): ?JsonSchemaProvider
    {
        return $this->schemaProvider;
    }
}
