<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;

/** @internal */
trait CompositionKeywords
{
    public function allOf(Schema|bool ...$schemas): static
    {
        return $this->withKeyword('allOf', self::normalizeCompositionSchemaList(array_values($schemas), 'allOf'));
    }

    public function anyOf(Schema|bool ...$schemas): static
    {
        return $this->withKeyword('anyOf', self::normalizeCompositionSchemaList(array_values($schemas), 'anyOf'));
    }

    public function oneOf(Schema|bool ...$schemas): static
    {
        return $this->withKeyword('oneOf', self::normalizeCompositionSchemaList(array_values($schemas), 'oneOf'));
    }

    public function not(Schema|bool $schema): static
    {
        return $this->withKeyword('not', $schema);
    }

    public function if(Schema|bool $schema): static
    {
        return $this->withKeyword('if', $schema);
    }

    public function then(Schema|bool $schema): static
    {
        return $this->withKeyword('then', $schema);
    }

    public function else(Schema|bool $schema): static
    {
        return $this->withKeyword('else', $schema);
    }

    /**
     * @param list<Schema|bool> $schemas
     * @return non-empty-list<Schema|bool>
     */
    private static function normalizeCompositionSchemaList(array $schemas, string $keyword): array
    {
        if ($schemas === []) {
            throw new InvalidArgumentException("JSON Schema {$keyword} must contain at least one schema value.");
        }

        return $schemas;
    }

    private static function serializeCompositionSchemaValue(Schema|bool $schema, bool $jsonValue): mixed
    {
        return $schema instanceof Schema ? ($jsonValue ? $schema->jsonSerialize() : $schema->toArray()) : $schema;
    }

    private static function serializeCompositionKeywordValue(mixed $value, bool $jsonValue): mixed
    {
        if ($value instanceof Schema || is_bool($value)) {
            return self::serializeCompositionSchemaValue($value, $jsonValue);
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::serializeCompositionKeywordValue($item, $jsonValue), $value);
        }

        return $value;
    }

    abstract protected function withKeyword(string $keyword, mixed $value): static;
}
