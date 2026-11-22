<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

/**
 * The HTTP status and headers of the response to Restate ({@see Invocation::responseHead()}).
 *
 * @internal
 */
final readonly class ResponseHead
{
    /** @param list<array{0: string, 1: string}> $headers */
    public function __construct(
        public int $statusCode,
        public array $headers,
    ) {
    }
}
