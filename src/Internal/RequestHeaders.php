<?php

declare(strict_types=1);

namespace Restate\Internal;

use InvalidArgumentException;

/**
 * Validates and normalizes the headers a handler passes to a call or send.
 *
 * @internal
 */
final class RequestHeaders
{
    private const string HEADER_NAME_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/";

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return list<array{0: string, 1: string}>
     */
    public static function normalize(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $normalized[] = self::normalizePair($name, $value);
                continue;
            }

            if (is_array($value) && array_keys($value) === [0, 1] && is_string($value[0]) && is_string($value[1])) {
                $normalized[] = self::normalizePair($value[0], $value[1]);
                continue;
            }

            throw new InvalidArgumentException('Request headers must be a string map or a list of [name, value] string pairs.');
        }

        return $normalized;
    }

    /** @return array{0: string, 1: string} */
    private static function normalizePair(string $name, string $value): array
    {
        if ($name === '') {
            throw new InvalidArgumentException('Request header name must not be empty.');
        }
        if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Invalid request header name {$name}.");
        }
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException("Invalid request header value for {$name}.");
        }

        return [$name, $value];
    }
}
