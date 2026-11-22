<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Attributes;

use InvalidArgumentException;
use JsonException;
use Restate\JsonSchema as JsonSchemaFactory;
use Restate\JsonSchema\Schema;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class RawSchema
{
    /** @param array<array-key, mixed>|bool $schema */
    public function __construct(
        public readonly array|bool $schema,
    ) {
        self::assertObjectOrBooleanSchema($schema);
        self::assertJsonEncodable($schema);
    }

    public function toSchema(): Schema
    {
        if ($this->schema === true) {
            return JsonSchemaFactory::alwaysValid();
        }

        if ($this->schema === false) {
            return JsonSchemaFactory::neverValid();
        }

        return JsonSchemaFactory::raw($this->schema);
    }

    /** @param array<array-key, mixed>|bool $schema */
    private static function assertObjectOrBooleanSchema(array|bool $schema): void
    {
        if (is_bool($schema)) {
            return;
        }

        if ($schema !== [] && array_is_list($schema)) {
            throw new InvalidArgumentException('Raw JSON Schema attributes must be JSON object or boolean schemas.');
        }

        foreach ($schema as $key => $_) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Raw JSON Schema attributes must be JSON object or boolean schemas.');
            }
        }
    }

    private static function assertJsonEncodable(mixed $schema): void
    {
        try {
            json_encode($schema, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Raw JSON Schema attributes must be JSON encodable.', previous: $e);
        }
    }
}
