<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Generation;

use BackedEnum;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Restate\JsonSchema as JsonSchemaFactory;
use Restate\JsonSchema\Attributes\JsonName;
use Restate\JsonSchema\Attributes\RawSchema;
use Restate\JsonSchema\ArraySchema;
use Restate\JsonSchema\BooleanValueSchema;
use Restate\JsonSchema\IntegerSchema;
use Restate\JsonSchema\NullSchema;
use Restate\JsonSchema\NumberSchema;
use Restate\JsonSchema\ObjectSchema;
use Restate\JsonSchema\Schema;
use Restate\JsonSchema\StringSchema;
use Restate\JsonSchema\UnionSchema;
use Restate\JsonSchema\Type;

final class SchemaGenerator
{
    /** @param class-string $className */
    public function schemaFor(string $className): Schema
    {
        $definitions = [];
        $processing = [];

        $schema = $this->schemaForClass($className, $definitions, $processing);
        if ($definitions !== []) {
            $schema = self::defs($schema, $definitions);
        }

        return $schema;
    }

    /**
     * @param class-string $className
     * @param array<string, Schema> $definitions
     * @param array<class-string, true> $processing
     */
    private function schemaForClass(string $className, array &$definitions, array $processing): Schema
    {
        if (isset($processing[$className])) {
            throw new InvalidArgumentException("DTO {$className} contains recursive JSON Schema references.");
        }

        $processing[$className] = true;

        if (($rawSchema = self::rawSchemaAttributeFor(new ReflectionClass($className))) !== null) {
            return $rawSchema->toSchema();
        }

        if (is_a($className, ProvidesSchema::class, true)) {
            return $className::jsonSchema($this);
        }

        if (enum_exists($className) && is_a($className, BackedEnum::class, true)) {
            /** @var class-string<BackedEnum> $className */
            return self::schemaForBackedEnum($className);
        }

        $class = new ReflectionClass($className);
        $properties = $this->propertiesForClass($class, $definitions, $processing);

        $schema = JsonSchemaFactory::object(...$properties);

        if (is_a($className, ProvidesExamples::class, true)) {
            $schema = self::examples($schema, [...$className::jsonSchemaExamples()]);
        }

        return $schema;
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<string, Schema> $definitions
     * @param array<class-string, true> $processing
     * @return list<\Restate\JsonSchema\PropertySchema>
     */
    private function propertiesForClass(ReflectionClass $class, array &$definitions, array $processing): array
    {
        $constructor = $class->getConstructor();
        if ($constructor !== null && $constructor->getParameters() !== []) {
            if (!$constructor->isPublic()) {
                throw new InvalidArgumentException("DTO {$class->getName()} must have a public constructor.");
            }

            $properties = [];
            foreach ($constructor->getParameters() as $parameter) {
                $properties[] = $this->propertyForParameter($parameter, $class->getName(), $definitions, $processing);
            }

            return $properties;
        }

        $properties = [];
        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            // Static and virtual (hooked, unbacked) properties hold no per-object data, so they are not in the JSON Wire Shape.
            if ($property->isStatic() || $property->isVirtual()) {
                continue;
            }

            $properties[] = $this->propertyForProperty($property, $class->getName(), $definitions, $processing);
        }

        return $properties;
    }

    /**
     * @param array<string, Schema> $definitions
     * @param array<class-string, true> $processing
     */
    private function propertyForParameter(ReflectionParameter $parameter, string $className, array &$definitions, array $processing): \Restate\JsonSchema\PropertySchema
    {
        if (!$parameter->isPromoted()) {
            throw new InvalidArgumentException("DTO {$className} constructor parameters must be promoted properties.");
        }

        $fieldSchema = self::rawSchemaAttributeFor($parameter)?->toSchema()
            ?? $this->schemaForType($parameter->getType(), $className, $parameter->getName(), $definitions, $processing);

        $property = JsonSchemaFactory::property(self::jsonNameFor($parameter), $fieldSchema);

        return $parameter->isDefaultValueAvailable() ? $property : $property->required();
    }

    /**
     * @param array<string, Schema> $definitions
     * @param array<class-string, true> $processing
     */
    private function propertyForProperty(ReflectionProperty $property, string $className, array &$definitions, array $processing): \Restate\JsonSchema\PropertySchema
    {
        $fieldSchema = self::rawSchemaAttributeFor($property)?->toSchema()
            ?? $this->schemaForType($property->getType(), $className, $property->getName(), $definitions, $processing);

        $propertySchema = JsonSchemaFactory::property(self::jsonNameFor($property), $fieldSchema);

        return $property->hasDefaultValue() ? $propertySchema : $propertySchema->required();
    }

    /**
     * @param array<string, Schema> $definitions
     * @param array<class-string, true> $processing
     */
    private function schemaForType(?ReflectionType $type, string $className, string $propertyName, array &$definitions, array $processing): Schema
    {
        if ($type === null) {
            return JsonSchemaFactory::raw([]);
        }

        if ($type instanceof ReflectionIntersectionType) {
            throw UnsupportedJsonSchemaTypeException::forProperty($className, $propertyName, 'intersection');
        }

        if ($type instanceof ReflectionUnionType) {
            return $this->schemaForUnionType($type, $className, $propertyName, $definitions, $processing);
        }

        if (!$type instanceof ReflectionNamedType) {
            throw UnsupportedJsonSchemaTypeException::forProperty($className, $propertyName, 'unknown');
        }

        $schema = $this->schemaForNamedType($type, $className, $propertyName, $definitions, $processing);
        if ($type->allowsNull() && !in_array($type->getName(), ['mixed', 'null'], true)) {
            return $this->nullableSchema($schema, self::jsonTypeForNamedType($type));
        }

        return $schema;
    }

    /**
     * @param array<string, Schema> $definitions
     * @param array<class-string, true> $processing
     */
    private function schemaForNamedType(ReflectionNamedType $type, string $className, string $propertyName, array &$definitions, array $processing): Schema
    {
        if ($type->isBuiltin()) {
            return match ($type->getName()) {
                'string' => JsonSchemaFactory::string(),
                'int' => JsonSchemaFactory::integer(),
                'float' => JsonSchemaFactory::number(),
                'bool' => JsonSchemaFactory::boolean(),
                'null' => JsonSchemaFactory::null(),
                'array' => JsonSchemaFactory::array(),
                'object' => JsonSchemaFactory::object(),
                'mixed' => JsonSchemaFactory::raw([]),
                'false' => JsonSchemaFactory::boolean()->const(false),
                'true' => JsonSchemaFactory::boolean()->const(true),
                default => throw UnsupportedJsonSchemaTypeException::forProperty($className, $propertyName, $type->getName()),
            };
        }

        $nestedClassName = $type->getName();
        if (enum_exists($nestedClassName) && is_a($nestedClassName, BackedEnum::class, true)) {
            /** @var class-string<BackedEnum> $nestedClassName */
            return self::schemaForBackedEnum($nestedClassName);
        }

        if (!class_exists($nestedClassName)) {
            throw UnsupportedJsonSchemaTypeException::forProperty($className, $propertyName, $nestedClassName);
        }

        /** @var class-string<object> $nestedClassName */
        $definitionName = self::definitionName($nestedClassName);
        if (!array_key_exists($definitionName, $definitions)) {
            $definitions[$definitionName] = $this->schemaForClass($nestedClassName, $definitions, $processing);
        }

        return JsonSchemaFactory::ref("#/\$defs/{$definitionName}");
    }

    /**
     * @param class-string<BackedEnum> $enum
     */
    private static function schemaForBackedEnum(string $enum): Schema
    {
        $values = array_map(static fn (BackedEnum $case): int|string => $case->value, $enum::cases());

        return is_int($values[0] ?? null)
            ? JsonSchemaFactory::integer()->enum($values)
            : JsonSchemaFactory::string()->enum($values);
    }

    /**
     * @param array<string, Schema> $definitions
     * @param array<class-string, true> $processing
     */
    private function schemaForUnionType(ReflectionUnionType $type, string $className, string $propertyName, array &$definitions, array $processing): Schema
    {
        $jsonTypes = [];
        $schemas = [];

        foreach ($type->getTypes() as $unionType) {
            if (!$unionType instanceof ReflectionNamedType) {
                throw UnsupportedJsonSchemaTypeException::forProperty($className, $propertyName, 'unknown');
            }

            $schema = $this->schemaForNamedType($unionType, $className, $propertyName, $definitions, $processing);
            $jsonType = self::jsonTypeForNamedType($unionType);

            if ($jsonType === null) {
                $schemas[] = $schema;
                continue;
            }

            $jsonTypes[] = $jsonType;
            $schemas[] = $schema;
        }

        if ($schemas === []) {
            throw UnsupportedJsonSchemaTypeException::forProperty($className, $propertyName, $type->__toString());
        }
        if (count($jsonTypes) === count($schemas)) {
            return JsonSchemaFactory::union(...$jsonTypes);
        }

        return self::anyOf($schemas);
    }

    private function nullableSchema(Schema $schema, ?Type $type): Schema
    {
        if ($type !== null) {
            return JsonSchemaFactory::union($type, Type::Null);
        }

        return self::anyOf([$schema, JsonSchemaFactory::null()]);
    }

    /** @param non-empty-list<Schema> $schemas */
    private static function anyOf(array $schemas): Schema
    {
        return JsonSchemaFactory::raw([
            'anyOf' => array_map(static fn (Schema $schema): mixed => $schema->toArray(), $schemas),
        ]);
    }

    private static function jsonTypeForNamedType(ReflectionNamedType $type): ?Type
    {
        if (!$type->isBuiltin()) {
            return null;
        }

        return match ($type->getName()) {
            'string' => Type::String,
            'int' => Type::Integer,
            'float' => Type::Number,
            'bool' => Type::Boolean,
            'null' => Type::Null,
            'array' => Type::Array,
            'object' => Type::Object,
            default => null,
        };
    }

    /** @param class-string<object> $className */
    private static function definitionName(string $className): string
    {
        return new ReflectionClass($className)->getShortName();
    }

    /** @param ReflectionClass<object>|ReflectionParameter|ReflectionProperty $reflection */
    private static function rawSchemaAttributeFor(ReflectionClass|ReflectionParameter|ReflectionProperty $reflection): ?RawSchema
    {
        $attributes = $reflection->getAttributes(RawSchema::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private static function jsonNameFor(ReflectionParameter|ReflectionProperty $reflection): string
    {
        $attributes = $reflection->getAttributes(JsonName::class);

        return $attributes === [] ? $reflection->getName() : $attributes[0]->newInstance()->name;
    }

    /** @param array<array-key, mixed> $examples */
    private static function examples(Schema $schema, array $examples): Schema
    {
        return match (true) {
            $schema instanceof ArraySchema => $schema->examples($examples),
            $schema instanceof ObjectSchema => $schema->examples($examples),
            $schema instanceof StringSchema => $schema->examples($examples),
            $schema instanceof IntegerSchema => $schema->examples($examples),
            $schema instanceof NumberSchema => $schema->examples($examples),
            $schema instanceof BooleanValueSchema => $schema->examples($examples),
            $schema instanceof NullSchema => $schema->examples($examples),
            $schema instanceof UnionSchema => $schema->examples($examples),
            default => throw new InvalidArgumentException('JSON Schema examples cannot be applied to this schema.'),
        };
    }

    /** @param array<string, Schema> $definitions */
    private static function defs(Schema $schema, array $definitions): Schema
    {
        if (!$schema instanceof ObjectSchema) {
            throw new InvalidArgumentException('JSON Schema definitions can only be applied to object schemas.');
        }

        return $schema->defs($definitions);
    }
}
