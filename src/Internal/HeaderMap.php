<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * The request headers of a PHP server request or a PSR-7 request, as name/value pairs with lowercase names.
 *
 * @internal
 */
final class HeaderMap
{
    /**
     * Bytes that are not UTF-8 in header names and values are replaced by U+FFFD.
     *
     * An empty `CONTENT_TYPE` or `CONTENT_LENGTH` is no header: FastCGI front doors always pass both, empty when the
     * request had none (nginx's `fastcgi_params`: `fastcgi_param CONTENT_TYPE $content_type;`).
     *
     * @param array<string, mixed> $server
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function fromServer(array $server): array
    {
        $headers = [];
        $hasCanonicalContentType = isset($server['CONTENT_TYPE']) && is_string($server['CONTENT_TYPE']) && $server['CONTENT_TYPE'] !== '';
        $hasCanonicalContentLength = isset($server['CONTENT_LENGTH']) && is_string($server['CONTENT_LENGTH']) && $server['CONTENT_LENGTH'] !== '';

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if ($key === 'CONTENT_TYPE') {
                if ($value !== '') {
                    $headers[] = ['content-type', $value];
                }
                continue;
            }
            if ($key === 'CONTENT_LENGTH') {
                if ($value !== '') {
                    $headers[] = ['content-length', $value];
                }
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                if ($name === 'content-type' && $hasCanonicalContentType) {
                    continue;
                }
                if ($name === 'content-length' && $hasCanonicalContentLength) {
                    continue;
                }

                $headers[] = [$name, $value];
            }
        }

        return self::utf8($headers);
    }

    /**
     * The headers of a PSR-7 message (`MessageInterface::getHeaders()`), names lowercased and the values of a repeated
     * header joined with ", " as PHP's SAPIs do. Bytes that are not UTF-8 are replaced by U+FFFD.
     *
     * @param array<array-key, array<string>> $headers PHP turns a numeric header name into an integer key
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function fromLists(array $headers): array
    {
        $pairs = [];
        foreach ($headers as $name => $values) {
            $pairs[] = [strtolower((string) $name), implode(', ', $values)];
        }

        return self::utf8($pairs);
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function utf8(array $headers): array
    {
        // Headers go to the protocol, which requires UTF-8; PHP passes whatever bytes the client sent.
        return array_map(static fn (array $header): array => [Utf8::substitute($header[0]), Utf8::substitute($header[1])], $headers);
    }
}
