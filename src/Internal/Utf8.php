<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * PHP strings need not be UTF-8; the Restate protocol needs it. Text bound for the protocol (request headers, failure
 * metadata) is made valid here instead of failing the request: invalid byte sequences become U+FFFD, the same
 * replacement the Rust core applies to failure messages and JSON_INVALID_UTF8_SUBSTITUTE applies when encoding.
 *
 * @internal
 */
final class Utf8
{
    private function __construct()
    {
    }

    public static function substitute(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        // A JSON round trip is exact for valid UTF-8 and needs nothing beyond ext-json.
        $decoded = json_decode(json_encode($text, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), flags: JSON_THROW_ON_ERROR);
        assert(is_string($decoded));

        return $decoded;
    }
}
