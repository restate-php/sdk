<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Internal;

use stdClass;

/** @internal */
final class JsonObject
{
    private function __construct()
    {
    }

    /**
     * @param array<string|int, mixed> $properties
     * @return array<string, mixed>|stdClass
     */
    public static function fromProperties(array $properties, bool $emptyObject = false): array|stdClass
    {
        if ($emptyObject && $properties === []) {
            return new stdClass();
        }

        $byName = [];
        foreach ($properties as $name => $value) {
            if (is_int($name)) {
                // PHP turns numeric string keys into integers; only an object keeps them as JSON member names.
                return self::toObject($properties);
            }
            $byName[$name] = $value;
        }

        return $byName;
    }

    /**
     * @param list<array{name: string, value: mixed}> $entries
     * @return array<string, mixed>|stdClass
     */
    public static function fromNamedEntries(array $entries): array|stdClass
    {
        if (self::entriesNeedObjectValue($entries)) {
            $object = new stdClass();
            foreach ($entries as $entry) {
                $object->{$entry['name']} = $entry['value'];
            }

            return $object;
        }

        $values = [];
        foreach ($entries as $entry) {
            $values[$entry['name']] = $entry['value'];
        }

        return $values;
    }

    /** @param array<string|int, mixed> $properties */
    private static function toObject(array $properties): stdClass
    {
        $object = new stdClass();
        foreach ($properties as $name => $value) {
            $object->{(string) $name} = $value;
        }

        return $object;
    }

    /** @param list<array{name: string, value: mixed}> $entries */
    private static function entriesNeedObjectValue(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (!self::arrayKeyPreservesString($entry['name'])) {
                return true;
            }
        }

        return false;
    }

    private static function arrayKeyPreservesString(string $name): bool
    {
        $array = [$name => true];

        return array_key_first($array) === $name;
    }
}
