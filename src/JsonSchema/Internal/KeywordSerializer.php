<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Internal;

use Restate\JsonSchema\JsonValue;
use Restate\JsonSchema\Schema;
use stdClass;

/** @internal */
final class KeywordSerializer
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $keywords
     * @param list<string> $keywordOrder
     * @param callable(string, mixed, bool): mixed $serializeKeyword
     * @return array<string, mixed>
     */
    public static function ordered(array $keywords, array $keywordOrder, bool $jsonValue, callable $serializeKeyword): array
    {
        $schema = [];
        foreach ($keywordOrder as $keyword) {
            if (!array_key_exists($keyword, $keywords)) {
                continue;
            }

            $schema[$keyword] = $serializeKeyword($keyword, $keywords[$keyword], $jsonValue);
        }

        return $schema;
    }

    public static function schemaToValue(Schema|bool $schema, bool $jsonValue): mixed
    {
        return $schema instanceof Schema ? ($jsonValue ? $schema->jsonSerialize() : $schema->toArray()) : $schema;
    }

    public static function jsonValueToValue(JsonValue $value, bool $jsonValue): mixed
    {
        return $jsonValue ? $value->jsonSerialize() : $value->toArray();
    }

    /**
     * @param list<JsonValue> $values
     * @return list<mixed>
     */
    public static function jsonValueListToValue(array $values, bool $jsonValue): array
    {
        return array_map(static fn (JsonValue $value): mixed => self::jsonValueToValue($value, $jsonValue), $values);
    }

    public static function keywordValueToValue(mixed $value, bool $jsonValue): mixed
    {
        if ($value instanceof Schema || is_bool($value)) {
            return self::schemaToValue($value, $jsonValue);
        }

        if ($value instanceof JsonValue) {
            return self::jsonValueToValue($value, $jsonValue);
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::keywordValueToValue($item, $jsonValue), $value);
        }

        return $value;
    }

    /**
     * @param list<array{name: string, schema: Schema|bool}> $schemas
     * @return array<string, mixed>|stdClass
     */
    public static function schemaMapToValue(array $schemas, bool $jsonValue): array|stdClass
    {
        $entries = [];
        foreach ($schemas as $schema) {
            $entries[] = ['name' => $schema['name'], 'value' => self::schemaToValue($schema['schema'], $jsonValue)];
        }

        return self::objectFromEntries($entries);
    }

    /**
     * @param list<array{name: string, value: mixed}> $entries
     * @return array<string, mixed>|stdClass
     */
    public static function objectFromEntries(array $entries): array|stdClass
    {
        if ($entries === []) {
            return new stdClass();
        }

        return JsonObject::fromNamedEntries($entries);
    }

    /**
     * @param array<string|int, mixed>|stdClass $root
     * @return array<string, mixed>|stdClass
     */
    public static function documentRoot(array|stdClass $root, string $dialectUri): array|stdClass
    {
        $document = ['$schema' => $dialectUri];
        $properties = $root instanceof stdClass ? get_object_vars($root) : $root;

        foreach ($properties as $name => $value) {
            if ((string) $name === '$schema') {
                continue;
            }

            $document[$name] = $value;
        }

        return JsonObject::fromProperties($document);
    }
}
