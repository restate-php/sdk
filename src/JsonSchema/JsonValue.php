<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use stdClass;

final class JsonValue implements JsonSerializable
{
    private function __construct(private readonly mixed $value)
    {
    }

    public static function from(mixed $value): self
    {
        $normalized = self::normalize($value);
        self::assertJsonEncodable($normalized);

        return new self($normalized);
    }

    public static function null(): self
    {
        return new self(null);
    }

    public static function list(mixed ...$values): self
    {
        $normalized = self::normalizeList($values);
        self::assertJsonEncodable($normalized);

        return new self($normalized);
    }

    /** @param array<array-key, mixed>|stdClass $properties */
    public static function object(array|stdClass $properties = []): self
    {
        $normalized = $properties instanceof stdClass
            ? self::normalizeObject(get_object_vars($properties))
            : self::normalizeObject($properties);
        self::assertJsonEncodable($normalized);

        return new self($normalized);
    }

    public function toArray(): mixed
    {
        return self::copy($this->value);
    }

    public function toJson(): string
    {
        return json_encode($this->toJsonValue(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function toJsonValue(): mixed
    {
        return self::copy($this->value);
    }

    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->toJsonValue();
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toJsonValue();
        }

        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('JSON literal numbers must be finite.');
            }

            return $value;
        }

        if (is_array($value)) {
            return array_is_list($value) ? self::normalizeList($value) : self::normalizeObject($value);
        }

        if ($value instanceof stdClass) {
            return self::normalizeObject(get_object_vars($value));
        }

        throw new InvalidArgumentException('JSON literal values must be strings, integers, numbers, booleans, null, arrays, objects, or JsonValue instances.');
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<mixed>
     */
    private static function normalizeList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $normalized[] = self::normalize($value);
        }

        return $normalized;
    }

    /** @param array<array-key, mixed> $properties */
    private static function normalizeObject(array $properties): stdClass
    {
        $object = new stdClass();
        foreach ($properties as $name => $value) {
            $object->{(string) $name} = self::normalize($value);
        }

        return $object;
    }

    private static function copy(mixed $value): mixed
    {
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $item) {
                $copy[] = self::copy($item);
            }

            return $copy;
        }

        if ($value instanceof stdClass) {
            $copy = new stdClass();
            foreach (get_object_vars($value) as $name => $propertyValue) {
                $copy->{$name} = self::copy($propertyValue);
            }

            return $copy;
        }

        return $value;
    }

    private static function assertJsonEncodable(mixed $value): void
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('JSON literal values must be JSON encodable.', previous: $e);
        }
    }
}
