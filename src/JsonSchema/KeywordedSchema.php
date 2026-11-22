<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use Restate\JsonSchema\Internal\KeywordSerializer;

/**
 * A schema object for one JSON type (or a list of them), named by the using class's `TYPE` constant and serialized
 * in the order its `keywordOrder()` gives.
 *
 * @internal
 */
trait KeywordedSchema
{
    use AnnotationKeywords;
    use CompositionKeywords;
    use KeywordBackedSchema;
    use PropertyConvertibleSchema;
    use ReferenceKeywords;

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

    public function nullable(): static
    {
        return $this->withNullableType(self::TYPE);
    }

    /** @return array<string, mixed> */
    private function serialize(bool $jsonValue): array
    {
        $keywords = ['type' => self::TYPE, ...$this->keywords()];

        return KeywordSerializer::ordered($keywords, self::keywordOrder(), $jsonValue, self::serializeKeyword(...));
    }

    /** @return list<string> */
    abstract private static function keywordOrder(): array;

    private static function serializeKeyword(string $keyword, mixed $value, bool $jsonValue): mixed
    {
        return $keyword === '$defs'
            ? self::definitionsToValue($value, $jsonValue)
            : self::serializeValue($value, $jsonValue);
    }

    private static function serializeValue(mixed $value, bool $jsonValue): mixed
    {
        if ($value instanceof Schema || is_bool($value)) {
            return self::serializeCompositionKeywordValue($value, $jsonValue);
        }

        if ($value instanceof JsonValue) {
            return $jsonValue ? $value->jsonSerialize() : $value->toArray();
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::serializeValue($item, $jsonValue), $value);
        }

        return $value;
    }
}
