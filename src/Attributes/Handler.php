<?php

declare(strict_types=1);

namespace Restate\Attributes;

use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Handler
{
    /** @param array<array-key, mixed> $metadata */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $documentation = null,
        public readonly array $metadata = [],
        public readonly ?InvocationRetryPolicy $invocationRetryPolicy = null,
        public readonly ?InvocationConfiguration $invocationConfiguration = null,
    ) {
    }
}
