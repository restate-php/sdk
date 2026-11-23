<?php

declare(strict_types=1);

namespace Restate\Reflection\Internal;

use InvalidArgumentException;
use ReflectionIntersectionType;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Restate\JsonSchema as JsonSchemaFactory;
use Restate\JsonSchema\Schema;
use Restate\JsonSchema\Type;
use Restate\JsonSchemaMetadata;
use stdClass;

/**
 * Infers the JSON Schema Metadata of a payload type for the built-in schema provider.
 *
 * @internal
 */
final class JsonSchemaInferer
{
    /**
     * @param array<class-string, true> $seen
     * @return array<string, mixed>|bool true when nothing is known about the shape
     */
    public static function schemaFor(?ReflectionType $type, array $seen = []): array|bool
    {
        $schema = self::schemaObjectFor($type, $seen);

        return $schema === null ? true : self::schemaToArray($schema);
    }

    /**
     * @param array<class-string, true> $seen
     */
    private static function schemaObjectFor(?ReflectionType $type, array $seen = []): ?Schema
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            return self::schemaObjectForNamedType($type, $seen);
        }

        if ($type instanceof ReflectionUnionType) {
            return self::schemaObjectForUnionType($type, $seen);
        }

        if ($type instanceof ReflectionIntersectionType) {
            return null;
        }

        return null;
    }

    public static function metadataFor(?ReflectionType $type): JsonSchemaMetadata
    {
        $schema = self::schemaObjectFor($type);
        if ($schema === null) {
            return JsonSchemaMetadata::unknown();
        }

        return JsonSchemaMetadata::fromSchema($schema);
    }

    public static function isEmptyOutput(?ReflectionType $type): bool
    {
        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        return in_array($type->getName(), ['void', 'never'], true);
    }

    /**
     * @param array<class-string, true> $seen
     */
    private static function schemaObjectForNamedType(ReflectionNamedType $type, array $seen): ?Schema
    {
        $name = $type->getName();

        return self::schemaObjectForNamedTypeName($name, $type->isBuiltin(), $seen, $type->allowsNull() && $name !== 'null');
    }

    /**
     * @param array<class-string, true> $seen
     */
    private static function schemaObjectForUnionType(ReflectionUnionType $type, array $seen): ?Schema
    {
        $jsonTypes = [];
        $objectSchema = null;
        foreach ($type->getTypes() as $unionArm) {
            if (!$unionArm instanceof ReflectionNamedType) {
                return null;
            }

            $name = $unionArm->getName();
            $jsonType = self::jsonTypeForUnionArm($name);
            if ($jsonType === null) {
                if ($objectSchema !== null) {
                    return null;
                }

                $schema = self::schemaObjectForNamedTypeName($name, $unionArm->isBuiltin(), $seen);
                if ($schema === null || self::schemaType($schema) !== Type::Object) {
                    return null;
                }

                $objectSchema = $schema;
                $jsonType = Type::Object;
            }

            if (!in_array($jsonType, $jsonTypes, true)) {
                $jsonTypes[] = $jsonType;
            }
        }

        if ($objectSchema !== null) {
            if ($jsonTypes !== [Type::Object, Type::Null] && $jsonTypes !== [Type::Null, Type::Object]) {
                return null;
            }

            return self::nullableSchema($objectSchema);
        }

        if (count($jsonTypes) === 1) {
            return self::typedSchema($jsonTypes[0]);
        }

        return JsonSchemaFactory::union(...$jsonTypes);
    }

    private static function jsonTypeForUnionArm(string $name): ?Type
    {
        return match ($name) {
            'string' => Type::String,
            'int' => Type::Integer,
            'float' => Type::Number,
            'bool' => Type::Boolean,
            'null' => Type::Null,
            default => null,
        };
    }

    /**
     * @param array<class-string, true> $seen
     */
    private static function schemaObjectForNamedTypeName(string $name, bool $builtin, array $seen, bool $nullable = false): ?Schema
    {
        if ($builtin) {
            return match ($name) {
                'string' => self::typedSchema(Type::String, $nullable),
                'int' => self::typedSchema(Type::Integer, $nullable),
                'float' => self::typedSchema(Type::Number, $nullable),
                'bool' => self::typedSchema(Type::Boolean, $nullable),
                'array' => null,
                'null' => JsonSchemaFactory::null(),
                'true' => self::booleanLiteralSchema(true, $nullable),
                'false' => self::booleanLiteralSchema(false, $nullable),
                default => null,
            };
        }

        if (strcasecmp($name, stdClass::class) === 0) {
            return self::typedSchema(Type::Object, $nullable);
        }

        if (!class_exists($name) && !enum_exists($name)) {
            return null;
        }

        if (enum_exists($name)) {
            return self::schemaObjectForBackedEnum(new ReflectionEnum($name), $nullable);
        }

        $class = new ReflectionClass($name);

        if (DtoShapeFactory::shapeFor($class) === null) {
            return null;
        }

        return self::schemaObjectForDto($class->getName(), $nullable);
    }

    private static function typedSchema(Type $type, bool $nullable = false): Schema
    {
        if ($nullable) {
            return JsonSchemaFactory::union($type, Type::Null);
        }

        return match ($type) {
            Type::String => JsonSchemaFactory::string(),
            Type::Integer => JsonSchemaFactory::integer(),
            Type::Number => JsonSchemaFactory::number(),
            Type::Boolean => JsonSchemaFactory::boolean(),
            Type::Null => JsonSchemaFactory::null(),
            Type::Object => JsonSchemaFactory::object(),
            Type::Array => JsonSchemaFactory::array(),
        };
    }

    private static function booleanLiteralSchema(bool $value, bool $nullable = false): Schema
    {
        $schema = JsonSchemaFactory::boolean()->const($value);

        return $nullable
            ? JsonSchemaFactory::raw(['anyOf' => [$schema->toArray(), JsonSchemaFactory::null()->toArray()]])
            : $schema;
    }

    /**
     * @param ReflectionEnum<\UnitEnum> $enum
     */
    private static function schemaObjectForBackedEnum(ReflectionEnum $enum, bool $nullable = false): ?Schema
    {
        if (!$enum->isBacked()) {
            return null;
        }

        $values = [];
        foreach ($enum->getCases() as $case) {
            $values[] = self::backingValueFor($case);
        }

        if ($nullable) {
            $values[] = null;
        }

        $schema = $enum->getBackingType()->getName() === 'int'
            ? JsonSchemaFactory::integer()
            : JsonSchemaFactory::string();

        return ($nullable ? $schema->nullable() : $schema)->enum($values);
    }

    private static function backingValueFor(ReflectionEnumBackedCase $case): int|string
    {
        return $case->getBackingValue();
    }

    /** @param class-string $className */
    private static function schemaObjectForDto(string $className, bool $nullable = false): ?Schema
    {
        try {
            $schema = JsonSchemaFactory::schemaFor($className);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $nullable ? self::nullableSchema($schema) : $schema;
    }

    private static function nullableSchema(Schema $schema): Schema
    {
        $array = self::schemaToArray($schema);
        if (!is_array($array)) {
            return JsonSchemaFactory::raw(['anyOf' => [$array, JsonSchemaFactory::null()->toArray()]]);
        }

        if (isset($array['type'])) {
            $type = $array['type'];
            if (is_string($type)) {
                $array['type'] = $type === Type::Null->value ? $type : [$type, Type::Null->value];

                return JsonSchemaFactory::raw($array);
            }

            if (is_array($type) && array_is_list($type) && !in_array(Type::Null->value, $type, true)) {
                $array['type'] = [...$type, Type::Null->value];

                return JsonSchemaFactory::raw($array);
            }
        }

        return JsonSchemaFactory::raw(['anyOf' => [$array, JsonSchemaFactory::null()->toArray()]]);
    }

    private static function schemaType(Schema $schema): ?Type
    {
        $array = self::schemaToArray($schema);
        if (!is_array($array) || !isset($array['type']) || !is_string($array['type'])) {
            return null;
        }

        return Type::tryFrom($array['type']);
    }

    /** @return array<string, mixed>|bool */
    private static function schemaToArray(Schema $schema): array|bool
    {
        $value = $schema->toArray();
        if (is_bool($value)) {
            return $value;
        }

        if ($value instanceof stdClass) {
            return self::objectToArray($value);
        }

        return is_array($value) ? self::arrayToArray($value) : true;
    }

    /**
     * @param array<array-key, mixed> $array
     * @return array<array-key, mixed>
     */
    private static function arrayToArray(array $array): array
    {
        foreach ($array as $name => $value) {
            $array[$name] = self::valueToArray($value);
        }

        return $array;
    }

    /** @return array<string, mixed> */
    private static function objectToArray(stdClass $object): array
    {
        $array = [];
        foreach (get_object_vars($object) as $name => $value) {
            $array[$name] = self::valueToArray($value);
        }

        return $array;
    }

    private static function valueToArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return self::objectToArray($value);
        }

        return is_array($value) ? self::arrayToArray($value) : $value;
    }
}
