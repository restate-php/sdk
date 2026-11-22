<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Restate\JsonSchema\JsonValue;
use Restate\JsonSchema\Schema;
use stdClass;

final readonly class JsonSchemaMetadata
{
    private function __construct(
        private readonly Schema|JsonValue|null $document,
    ) {
    }

    /**
     * A JSON Schema document given as decoded JSON, published as is. It may declare any `$schema`: the builders
     * (`JsonSchema::…`) only produce Draft 2020-12, but Restate stores the document without interpreting it.
     *
     * @param array<array-key, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        if ($document === [] || array_is_list($document)) {
            throw new InvalidArgumentException('JSON Schema document must be a non-empty JSON object.');
        }

        $stringKeyedDocument = [];
        foreach ($document as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('JSON Schema document must be a non-empty JSON object.');
            }

            $stringKeyedDocument[$key] = $value;
        }

        self::assertJsonEncodable($stringKeyedDocument);

        return new self(JsonValue::object($stringKeyedDocument));
    }

    public static function fromSchema(Schema $schema): self
    {
        self::assertJsonSchemaDocument($schema);

        return new self($schema);
    }

    public static function any(): self
    {
        return self::fromSchema(JsonSchema::raw([]));
    }

    public static function unknown(): self
    {
        return new self(null);
    }

    public function isUnknown(): bool
    {
        return $this->document === null;
    }

    /** @return array<string, mixed>|stdClass|bool */
    public function toArray(): array|stdClass|bool
    {
        if ($this->document === null) {
            throw new LogicException('Unknown JSON Schema Metadata has no JSON Schema document.');
        }

        return self::schemaValue($this->document);
    }

    /** @return array<string, mixed>|stdClass|bool */
    public function toManifestValue(): array|stdClass|bool
    {
        if ($this->document === null) {
            return true;
        }

        return self::schemaValue($this->document);
    }

    private static function assertJsonEncodable(mixed $document): void
    {
        try {
            json_encode($document, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('JSON Schema document must be JSON encodable.', previous: $e);
        }
    }

    private static function assertJsonSchemaDocument(Schema $schema): void
    {
        $value = self::schemaValue($schema);
        self::assertJsonEncodable($value);
    }

    /** @return array<string, mixed>|stdClass|bool */
    private static function schemaValue(Schema|JsonValue $document): array|stdClass|bool
    {
        $value = $document->toArray();

        if (is_bool($value)) {
            return $value;
        }

        if ($value instanceof stdClass) {
            return self::objectToManifestValue($value);
        }

        if (is_array($value)) {
            if ($value === [] || array_is_list($value)) {
                throw new InvalidArgumentException('JSON Schema document must be a JSON object or boolean schema.');
            }

            foreach ($value as $key => $_) {
                if (!is_string($key)) {
                    throw new InvalidArgumentException('JSON Schema document must be a JSON object or boolean schema.');
                }
            }

            /** @var array<string, mixed> $value */
            return self::arrayToManifestValue($value);
        }

        throw new InvalidArgumentException('JSON Schema document must be a JSON object or boolean schema.');
    }

    /** @return array<string, mixed>|stdClass */
    private static function objectToManifestValue(stdClass $object): array|stdClass
    {
        $properties = get_object_vars($object);
        if ($properties === []) {
            return new stdClass();
        }

        return self::arrayToManifestValue($properties);
    }

    /**
     * @param array<array-key, mixed> $array
     * @return array<array-key, mixed>
     */
    private static function arrayToManifestValue(array $array): array
    {
        foreach ($array as $key => $value) {
            if ($value instanceof stdClass) {
                $array[$key] = self::objectToManifestValue($value);
                continue;
            }

            if (is_array($value)) {
                $array[$key] = self::arrayToManifestValue($value);
            }
        }

        return $array;
    }
}
