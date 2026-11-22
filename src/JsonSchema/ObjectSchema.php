<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;
use Restate\JsonSchema\Internal\KeywordOrder;
use Restate\JsonSchema\Internal\KeywordSerializer;

final class ObjectSchema implements Schema
{
    use KeywordBackedSchema;
    use AnnotationKeywords;
    use CompositionKeywords;
    use ReferenceKeywords;

    /**
     * @param array<string, mixed> $keywords
     * @param list<PropertySchema> $properties
     */
    private function __construct(
        array $keywords,
        private readonly array $properties,
        private readonly Schema|bool|null $additionalProperties,
    ) {
        $this->keywords = $keywords;
    }

    /** @param array<array-key, mixed> $properties */
    public static function new(array $properties = []): self
    {
        return new self([], self::normalizeProperties($properties), null);
    }

    public function property(PropertySchema|string $property, Schema|null $schema = null): self
    {
        if (is_string($property)) {
            if (!$schema instanceof Schema) {
                throw new InvalidArgumentException('JSON Schema object properties need a schema value.');
            }

            $property = PropertySchema::new($property, $schema);
        } else {
            if ($schema !== null) {
                throw new InvalidArgumentException('JSON Schema named property entries must not include a second schema argument.');
            }
        }

        if ($this->hasProperty($property->propertyName())) {
            throw new InvalidArgumentException(sprintf('Duplicate JSON Schema object property "%s".', $property->propertyName()));
        }

        return new self($this->keywords(), [...$this->properties, $property], $this->additionalProperties);
    }

    public function properties(PropertySchema ...$properties): self
    {
        return new self($this->keywords(), self::normalizeProperties($properties), $this->additionalProperties);
    }

    public function additionalProperties(Schema|bool $schema): self
    {
        return new self($this->keywords(), $this->properties, $schema);
    }

    /** @param array<array-key, mixed> $properties */
    public function patternProperties(array $properties): self
    {
        return $this->withKeyword('patternProperties', self::normalizeSchemaMap($properties, 'patternProperties'));
    }

    public function patternProperty(string $pattern, Schema|bool $schema): self
    {
        if (!$this->hasKeyword('patternProperties')) {
            return $this->withKeyword('patternProperties', self::normalizeSchemaMap([$pattern => $schema], 'patternProperties'));
        }

        return $this->withKeyword('patternProperties', self::withSchemaMapEntry($this->keyword('patternProperties'), $pattern, $schema, 'patternProperties'));
    }

    /** @param array<array-key, mixed> $requirements */
    public function dependentRequired(array $requirements): self
    {
        return $this->withKeyword('dependentRequired', self::normalizeDependentRequired($requirements));
    }

    /** @param array<array-key, mixed> $required */
    public function dependentRequiredProperty(string $name, array $required): self
    {
        if (!$this->hasKeyword('dependentRequired')) {
            return $this->withKeyword('dependentRequired', self::normalizeDependentRequired([$name => $required]));
        }

        return $this->withKeyword('dependentRequired', self::withDependentRequiredEntry($this->keyword('dependentRequired'), $name, $required));
    }

    /** @param array<array-key, mixed> $schemas */
    public function dependentSchemas(array $schemas): self
    {
        return $this->withKeyword('dependentSchemas', self::normalizeSchemaMap($schemas, 'dependentSchemas'));
    }

    public function dependentSchema(string $name, Schema|bool $schema): self
    {
        if (!$this->hasKeyword('dependentSchemas')) {
            return $this->withKeyword('dependentSchemas', self::normalizeSchemaMap([$name => $schema], 'dependentSchemas'));
        }

        return $this->withKeyword('dependentSchemas', self::withSchemaMapEntry($this->keyword('dependentSchemas'), $name, $schema, 'dependentSchemas'));
    }

    public function propertyNames(Schema|bool $schema): self
    {
        return $this->withKeyword('propertyNames', $schema);
    }

    public function unevaluatedProperties(Schema|bool $schema): self
    {
        return $this->withKeyword('unevaluatedProperties', $schema);
    }

    public function minProperties(int $minProperties): self
    {
        return $this->withKeyword('minProperties', self::nonNegativeInteger($minProperties, 'minProperties'));
    }

    public function maxProperties(int $maxProperties): self
    {
        return $this->withKeyword('maxProperties', self::nonNegativeInteger($maxProperties, 'maxProperties'));
    }

    public function nullable(): self
    {
        return $this->withNullableType('object');
    }

    /** @param array<array-key, mixed> $values */
    public function examples(array $values): static
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException('JSON Schema examples must be a list of JSON values.');
        }

        return $this->withKeyword('examples', array_map(JsonValue::from(...), $values));
    }

    public function required(string ...$names): self
    {
        return $this->withKeyword('required', self::normalizeRequired($names));
    }

    public function requireProperty(string $name): self
    {
        $required = $this->hasKeyword('required') ? $this->keyword('required') : [];

        return $this->withKeyword('required', self::appendRequired($required, $name));
    }

    public function denyAdditionalProperties(): self
    {
        return $this->additionalProperties(false);
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
        return new self($keywords, $this->properties, $this->additionalProperties);
    }

    /**
     * @param array<array-key, mixed> $properties
     * @return list<PropertySchema>
     */
    private static function normalizeProperties(array $properties): array
    {
        $normalized = [];
        $seen = [];

        foreach ($properties as $property) {
            if (!$property instanceof PropertySchema) {
                throw new InvalidArgumentException('JSON Schema object properties must be named property schemas.');
            }

            $name = $property->propertyName();
            if (isset($seen[$name])) {
                throw new InvalidArgumentException(sprintf('Duplicate JSON Schema object property "%s".', $name));
            }

            $seen[$name] = true;
            $normalized[] = $property;
        }

        return $normalized;
    }

    private function hasProperty(string $name): bool
    {
        foreach ($this->properties as $property) {
            if ($property->propertyName() === $name) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function serialize(bool $jsonValue): array
    {
        $keywords = ['type' => 'object', ...$this->keywords()];

        if ($this->properties !== []) {
            $keywords['properties'] = self::propertiesToValue($this->properties, $jsonValue);
        }

        $required = self::requiredPropertyNames($this->properties, $this->hasKeyword('required') ? $this->keyword('required') : []);
        if ($required !== [] || $this->hasKeyword('required')) {
            $keywords['required'] = $required;
        }

        if ($this->additionalProperties !== null) {
            $keywords['additionalProperties'] = $this->additionalProperties instanceof Schema
                ? ($jsonValue ? $this->additionalProperties->jsonSerialize() : $this->additionalProperties->toArray())
                : $this->additionalProperties;
        }

        return KeywordSerializer::ordered($keywords, KeywordOrder::draft202012([
            'minProperties',
            'maxProperties',
            'properties',
            'patternProperties',
            'required',
            'dependentRequired',
            'additionalProperties',
            'propertyNames',
            'dependentSchemas',
            'unevaluatedProperties',
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
            'patternProperties', 'dependentSchemas' => self::schemaMapToValue($value, $jsonValue),
            'dependentRequired' => self::dependentRequiredToValue($value),
            'enum', 'examples' => KeywordSerializer::jsonValueListToValue($value, $jsonValue),
            'propertyNames', 'unevaluatedProperties' => self::schemaToValue($value, $jsonValue),
            default => $value,
        };
    }

    /**
     * @param list<PropertySchema> $properties
     * @return array<string, mixed>|\stdClass
     */
    private static function propertiesToValue(array $properties, bool $jsonValue): array|\stdClass
    {
        $entries = [];
        foreach ($properties as $property) {
            $entries[] = ['name' => $property->propertyName(), 'value' => $jsonValue ? $property->jsonSerialize() : $property->toArray()];
        }

        return KeywordSerializer::objectFromEntries($entries);
    }

    /**
     * @param list<array{name: string, schema: Schema|bool}> $schemas
     * @return array<string, mixed>|\stdClass
     */
    private static function schemaMapToValue(array $schemas, bool $jsonValue): array|\stdClass
    {
        return KeywordSerializer::schemaMapToValue($schemas, $jsonValue);
    }

    private static function schemaToValue(Schema|bool $schema, bool $jsonValue): mixed
    {
        return KeywordSerializer::schemaToValue($schema, $jsonValue);
    }

    /**
     * @param list<array{name: string, required: list<string>}> $requirements
     * @return array<string, list<string>>|\stdClass
     */
    private static function dependentRequiredToValue(array $requirements): array|\stdClass
    {
        $entries = [];
        foreach ($requirements as $requirement) {
            $entries[] = ['name' => $requirement['name'], 'value' => $requirement['required']];
        }

        return KeywordSerializer::objectFromEntries($entries);
    }

    /**
     * @param list<PropertySchema> $properties
     * @param list<string> $explicitRequired
     * @return list<string>
     */
    private static function requiredPropertyNames(array $properties, array $explicitRequired): array
    {
        $required = [];
        $seen = [];
        foreach ($properties as $property) {
            if ($property->isRequired()) {
                $name = $property->propertyName();
                $seen[$name] = true;
                $required[] = $name;
            }
        }

        foreach ($explicitRequired as $name) {
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $required[] = $name;
            }
        }

        return $required;
    }

    /**
     * @param array<array-key, mixed> $required
     * @return list<string>
     */
    private static function normalizeRequired(array $required): array
    {
        $normalized = [];
        $seen = [];

        foreach ($required as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('JSON Schema required entries must be strings.');
            }

            if (isset($seen[$name])) {
                throw new InvalidArgumentException(sprintf('Duplicate JSON Schema required name "%s".', $name));
            }

            $seen[$name] = true;
            $normalized[] = $name;
        }

        return $normalized;
    }

    /**
     * @param list<string> $required
     * @return non-empty-list<string>
     */
    private static function appendRequired(array $required, string $name): array
    {
        foreach ($required as $existing) {
            if ($existing === $name) {
                throw new InvalidArgumentException(sprintf('Duplicate JSON Schema required name "%s".', $name));
            }
        }

        return [...$required, $name];
    }

    private static function nonNegativeInteger(int $value, string $keyword): int
    {
        if ($value < 0) {
            throw new InvalidArgumentException("JSON Schema {$keyword} must be a non-negative integer.");
        }

        return $value;
    }

    private static function normalizeSchema(mixed $schema, string $keyword): Schema|bool
    {
        if (!$schema instanceof Schema && !is_bool($schema)) {
            throw new InvalidArgumentException("JSON Schema {$keyword} must be a boolean or schema value.");
        }

        return $schema;
    }

    /**
     * @param array<array-key, mixed> $schemas
     * @return list<array{name: string, schema: Schema|bool}>
     */
    private static function normalizeSchemaMap(array $schemas, string $keyword): array
    {
        $normalized = [];
        foreach ($schemas as $name => $schema) {
            $normalized[] = [
                'name' => (string) $name,
                'schema' => self::normalizeSchema($schema, $keyword),
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array{name: string, schema: Schema|bool}> $schemas
     * @return list<array{name: string, schema: Schema|bool}>
     */
    private static function withSchemaMapEntry(array $schemas, string $name, Schema|bool $schema, string $keyword): array
    {
        foreach ($schemas as $existing) {
            if ($existing['name'] === $name) {
                throw new InvalidArgumentException(sprintf('Duplicate JSON Schema %s name "%s".', $keyword, $name));
            }
        }

        $schemas[] = ['name' => $name, 'schema' => $schema];

        return $schemas;
    }

    /**
     * @param array<array-key, mixed> $dependentRequired
     * @return list<array{name: string, required: list<string>}>
     */
    private static function normalizeDependentRequired(array $dependentRequired): array
    {
        $normalized = [];
        foreach ($dependentRequired as $name => $required) {
            $name = (string) $name;

            if (!is_array($required) || !array_is_list($required)) {
                throw new InvalidArgumentException(sprintf('JSON Schema dependentRequired value for property "%s" must be a list of strings.', $name));
            }

            $normalized[] = ['name' => $name, 'required' => self::normalizeDependentRequiredList($required, $name)];
        }

        return $normalized;
    }

    /**
     * @param list<array{name: string, required: list<string>}> $requirements
     * @param array<array-key, mixed> $required
     * @return list<array{name: string, required: list<string>}>
     */
    private static function withDependentRequiredEntry(array $requirements, string $name, array $required): array
    {
        foreach ($requirements as $requirement) {
            if ($requirement['name'] === $name) {
                throw new InvalidArgumentException(sprintf('Duplicate JSON Schema dependentRequired property "%s".', $name));
            }
        }

        $requirements[] = ['name' => $name, 'required' => self::normalizeDependentRequiredList($required, $name)];

        return $requirements;
    }

    /**
     * @param array<array-key, mixed> $required
     * @return list<string>
     */
    private static function normalizeDependentRequiredList(array $required, string $propertyName): array
    {
        $normalized = [];
        $seen = [];

        foreach ($required as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException(sprintf('JSON Schema dependentRequired entries for property "%s" must be strings.', $propertyName));
            }

            if (isset($seen[$name])) {
                throw new InvalidArgumentException(sprintf('Duplicate JSON Schema dependentRequired name "%s" for property "%s".', $name, $propertyName));
            }

            $seen[$name] = true;
            $normalized[] = $name;
        }

        return $normalized;
    }
}
