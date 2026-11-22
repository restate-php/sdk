<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;
use Restate\JsonSchema\Internal\KeywordOrder;
use Restate\JsonSchema\Internal\KeywordSerializer;

final class ArraySchema implements Schema
{
    use KeywordBackedSchema;
    use AnnotationKeywords;
    use CompositionKeywords;
    use PropertyConvertibleSchema;
    use ReferenceKeywords;

    public static function new(): self
    {
        return new self([]);
    }

    public function items(Schema|bool $schema): self
    {
        return $this->withKeyword('items', $schema);
    }

    public function prefixItems(Schema|bool ...$schemas): self
    {
        return $this->withKeyword('prefixItems', self::normalizeSchemaList(array_values($schemas), 'prefixItems'));
    }

    public function contains(Schema|bool $schema): self
    {
        return $this->withKeyword('contains', $schema);
    }

    public function minContains(int|float $minContains): self
    {
        return $this->withKeyword('minContains', self::nonNegativeInteger($minContains, 'minContains'));
    }

    public function maxContains(int|float $maxContains): self
    {
        return $this->withKeyword('maxContains', self::nonNegativeInteger($maxContains, 'maxContains'));
    }

    public function minItems(int|float $minItems): self
    {
        return $this->withKeyword('minItems', self::nonNegativeInteger($minItems, 'minItems'));
    }

    public function maxItems(int|float $maxItems): self
    {
        return $this->withKeyword('maxItems', self::nonNegativeInteger($maxItems, 'maxItems'));
    }

    public function uniqueItems(bool $uniqueItems = true): self
    {
        return $this->withKeyword('uniqueItems', $uniqueItems);
    }

    public function unevaluatedItems(Schema|bool $schema): self
    {
        return $this->withKeyword('unevaluatedItems', $schema);
    }

    public function nullable(): self
    {
        return $this->withNullableType('array');
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return $this->serialize(false);
    }

    #[\Override]
    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->serialize(true);
    }

    /** @param array<string, mixed> $keywords */
    protected function withKeywords(array $keywords): static
    {
        return new self($keywords);
    }

    /** @return array<string, mixed> */
    private function serialize(bool $jsonValue): array
    {
        $keywords = ['type' => 'array', ...$this->keywords()];

        return KeywordSerializer::ordered($keywords, KeywordOrder::draft202012([
            'prefixItems',
            'items',
            'contains',
            'minContains',
            'maxContains',
            'minItems',
            'maxItems',
            'uniqueItems',
            'unevaluatedItems',
            ...KeywordOrder::valueAnnotations(),
        ]), $jsonValue, self::serializeKeyword(...));
    }

    private static function serializeKeyword(string $keyword, mixed $value, bool $jsonValue): mixed
    {
        if ($value instanceof JsonValue) {
            return KeywordSerializer::jsonValueToValue($value, $jsonValue);
        }

        return match ($keyword) {
            '$defs' => self::definitionsToValue($value, $jsonValue),
            'allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else' => self::serializeCompositionKeywordValue($value, $jsonValue),
            'items', 'contains', 'unevaluatedItems' => self::schemaToValue($value, $jsonValue),
            'prefixItems' => array_map(static fn (Schema|bool $schema): mixed => self::schemaToValue($schema, $jsonValue), $value),
            'enum', 'examples' => KeywordSerializer::jsonValueListToValue($value, $jsonValue),
            default => $value,
        };
    }

    private static function schemaToValue(Schema|bool $schema, bool $jsonValue): mixed
    {
        return KeywordSerializer::schemaToValue($schema, $jsonValue);
    }

    /**
     * @param list<Schema|bool> $schemas
     * @return non-empty-list<Schema|bool>
     */
    private static function normalizeSchemaList(array $schemas, string $keyword): array
    {
        if ($schemas === []) {
            throw new InvalidArgumentException("JSON Schema {$keyword} must contain at least one schema value.");
        }

        return $schemas;
    }

    private static function nonNegativeInteger(int|float $value, string $keyword): int
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException("JSON Schema {$keyword} must be finite.");
        }

        if ($value < 0 || (float) (int) $value !== (float) $value) {
            throw new InvalidArgumentException("JSON Schema {$keyword} must be a non-negative integer.");
        }

        return (int) $value;
    }
}
