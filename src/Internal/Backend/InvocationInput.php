<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

/**
 * The invocation's input ({@see Invocation::input()}).
 *
 * @internal
 */
final readonly class InvocationInput
{
    /**
     * @param int $randomSeed the unsigned 64-bit seed reinterpreted as a signed integer
     * @param list<array{0: string, 1: string}> $headers
     * @param ?string $scope what the invocation was called with (as are $limitKey and $idempotencyKey)
     */
    public function __construct(
        public string $invocationId,
        public int $randomSeed,
        public string $key,
        public array $headers,
        public string $input,
        public ?string $scope = null,
        public ?string $limitKey = null,
        public ?string $idempotencyKey = null,
    ) {
    }
}
