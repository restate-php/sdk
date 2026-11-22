<?php

declare(strict_types=1);

namespace Restate;

use Restate\JsonSchema\ArraySchema;
use Restate\JsonSchema\BooleanSchema;
use Restate\JsonSchema\BooleanValueSchema;
use Restate\JsonSchema\Document;
use Restate\JsonSchema\Generation\SchemaGenerator;
use Restate\JsonSchema\IntegerSchema;
use Restate\JsonSchema\NullSchema;
use Restate\JsonSchema\NumberSchema;
use Restate\JsonSchema\ObjectSchema;
use Restate\JsonSchema\PropertySchema;
use Restate\JsonSchema\RawSchema;
use Restate\JsonSchema\ReferenceSchema;
use Restate\JsonSchema\Schema;
use Restate\JsonSchema\StringSchema;
use Restate\JsonSchema\Type;
use Restate\JsonSchema\UnionSchema;

/**
 * Builds JSON Schema (Draft 2020-12) values: subschemas, and documents with `document()`.
 */
final class JsonSchema
{
    /** @param class-string $className */
    public static function schemaFor(string $className): Schema
    {
        return new SchemaGenerator()->schemaFor($className);
    }

    public static function string(): StringSchema
    {
        return StringSchema::new();
    }

    public static function array(Schema|bool ...$prefixItems): ArraySchema
    {
        $schema = ArraySchema::new();

        return $prefixItems === [] ? $schema : $schema->prefixItems(...$prefixItems);
    }

    public static function integer(): IntegerSchema
    {
        return IntegerSchema::new();
    }

    public static function number(): NumberSchema
    {
        return NumberSchema::new();
    }

    public static function boolean(): BooleanValueSchema
    {
        return BooleanValueSchema::new();
    }

    public static function null(): NullSchema
    {
        return NullSchema::new();
    }

    public static function union(Type ...$types): UnionSchema
    {
        return UnionSchema::new(array_values($types));
    }

    public static function object(PropertySchema ...$properties): ObjectSchema
    {
        return ObjectSchema::new($properties);
    }

    public static function property(string $name, Schema $schema): PropertySchema
    {
        return PropertySchema::new($name, $schema);
    }

    public static function ref(string $ref): ReferenceSchema
    {
        return ReferenceSchema::new($ref);
    }

    public static function dynamicRef(string $dynamicRef): ReferenceSchema
    {
        return ReferenceSchema::dynamic($dynamicRef);
    }

    public static function raw(mixed $content): RawSchema
    {
        return RawSchema::new($content);
    }

    public static function alwaysValid(): BooleanSchema
    {
        return BooleanSchema::true();
    }

    public static function neverValid(): BooleanSchema
    {
        return BooleanSchema::false();
    }

    public static function document(Schema $root): Document
    {
        return Document::new($root);
    }
}
