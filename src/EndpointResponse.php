<?php

declare(strict_types=1);

namespace Restate;

final readonly class EndpointResponse
{
    /** @param list<array{0: string, 1: string}> $headers */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
