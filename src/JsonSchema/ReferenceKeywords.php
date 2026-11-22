<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;
use Restate\JsonSchema\Internal\KeywordSerializer;

/** @internal */
trait ReferenceKeywords
{
    public function id(string $id): static
    {
        return $this->withKeyword('$id', $id);
    }

    public function anchor(string $anchor): static
    {
        return $this->withKeyword('$anchor', $anchor);
    }

    public function ref(string $ref): static
    {
        return $this->withKeyword('$ref', $ref);
    }

    public function dynamicRef(string $dynamicRef): static
    {
        return $this->withKeyword('$dynamicRef', $dynamicRef);
    }

    public function dynamicAnchor(string $dynamicAnchor): static
    {
        return $this->withKeyword('$dynamicAnchor', $dynamicAnchor);
    }

    /** @param array<array-key, mixed> $vocabulary */
    public function vocabulary(array $vocabulary): static
    {
        return $this->withKeyword('$vocabulary', JsonValue::object(self::normalizeVocabulary($vocabulary)));
    }

    /** @param array<array-key, mixed> $defs */
    public function defs(array $defs): static
    {
        return $this->withKeyword('$defs', self::normalizeDefinitions($defs));
    }

    public function def(string $name, Schema|bool $schema): static
    {
        $defs = $this->hasKeyword('$defs') ? $this->keyword('$defs') : [];

        return $this->withKeyword('$defs', self::withDefinition($defs, $name, $schema));
    }

    /**
     * @param array<array-key, mixed> $defs
     * @return list<array{name: string, schema: Schema|bool}>
     */
    private static function normalizeDefinitions(array $defs): array
    {
        $normalized = [];
        foreach ($defs as $name => $schema) {
            $normalized[] = [
                'name' => (string) $name,
                'schema' => self::normalizeDefinitionSchema($schema),
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array{name: string, schema: Schema|bool}> $defs
     * @return list<array{name: string, schema: Schema|bool}>
     */
    private static function withDefinition(array $defs, string $name, Schema|bool $schema): array
    {
        foreach ($defs as $def) {
            if ($def['name'] === $name) {
                throw new InvalidArgumentException(sprintf('Duplicate JSON Schema $defs name "%s".', $name));
            }
        }

        $defs[] = ['name' => $name, 'schema' => self::normalizeDefinitionSchema($schema)];

        return $defs;
    }

    private static function normalizeDefinitionSchema(mixed $schema): Schema|bool
    {
        if (!$schema instanceof Schema && !is_bool($schema)) {
            throw new InvalidArgumentException('JSON Schema $defs values must be booleans or schema values.');
        }

        return $schema;
    }

    /**
     * @param array<array-key, mixed> $vocabulary
     * @return array<string, bool>
     */
    private static function normalizeVocabulary(array $vocabulary): array
    {
        $normalized = [];
        foreach ($vocabulary as $uri => $required) {
            if (!is_bool($required)) {
                throw new InvalidArgumentException('JSON Schema $vocabulary values must be booleans.');
            }

            $normalized[(string) $uri] = $required;
        }

        return $normalized;
    }

    /**
     * @param list<array{name: string, schema: Schema|bool}> $defs
     * @return array<string, mixed>|\stdClass
     */
    private static function definitionsToValue(array $defs, bool $jsonValue): array|\stdClass
    {
        $entries = [];
        foreach ($defs as $def) {
            $entries[] = ['name' => $def['name'], 'value' => self::definitionSchemaToValue($def['schema'], $jsonValue)];
        }

        return KeywordSerializer::objectFromEntries($entries);
    }

    private static function definitionSchemaToValue(Schema|bool $schema, bool $jsonValue): mixed
    {
        return KeywordSerializer::schemaToValue($schema, $jsonValue);
    }

    abstract protected function hasKeyword(string $keyword): bool;

    abstract protected function keyword(string $keyword): mixed;

    abstract protected function withKeyword(string $keyword, mixed $value): static;
}
