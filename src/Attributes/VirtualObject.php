<?php

declare(strict_types=1);

namespace Restate\Attributes;

use Restate\InvocationRetryPolicy;
use Restate\InvocationConfiguration;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class VirtualObject
{
    /** @param array<array-key, mixed> $metadata */
    public function __construct(
        public readonly string $name,
        public readonly ?string $documentation = null,
        public readonly array $metadata = [],
        public readonly ?InvocationRetryPolicy $invocationRetryPolicy = null,
        public readonly ?InvocationConfiguration $invocationConfiguration = null,
    ) {
    }
}
