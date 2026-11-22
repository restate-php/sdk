<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use Restate\JsonSchema\Internal\KeywordOrder;
use Restate\JsonSchema\Internal\KeywordSerializer;

final class ReferenceSchema implements Schema
{
    use KeywordBackedSchema;
    use AnnotationKeywords;
    use CompositionKeywords;
    use PropertyConvertibleSchema;
    use ReferenceKeywords;

    public static function new(string $ref): self
    {
        return new self(['$ref' => $ref]);
    }

    public static function dynamic(string $dynamicRef): self
    {
        return new self(['$dynamicRef' => $dynamicRef]);
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
        $schema = [];

        foreach (KeywordOrder::draft202012Reference() as $keyword) {
            if (!$this->hasKeyword($keyword)) {
                continue;
            }

            $schema[$keyword] = self::serializeKeyword($keyword, $this->keyword($keyword), $jsonValue);
        }

        return $schema;
    }

    private static function serializeKeyword(string $keyword, mixed $value, bool $jsonValue): mixed
    {
        if ($keyword === '$defs') {
            return self::definitionsToValue($value, $jsonValue);
        }

        if ($value instanceof JsonValue) {
            return KeywordSerializer::jsonValueToValue($value, $jsonValue);
        }

        if ($keyword === 'enum' || $keyword === 'examples') {
            return KeywordSerializer::jsonValueListToValue($value, $jsonValue);
        }

        if ($value instanceof Schema || is_bool($value)) {
            return KeywordSerializer::schemaToValue($value, $jsonValue);
        }

        return KeywordSerializer::keywordValueToValue($value, $jsonValue);
    }
}
