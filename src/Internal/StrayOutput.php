<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * What PHP printed outside the protocol response (docs/decisions/0032-stray-output.md).
 *
 * @internal
 */
final readonly class StrayOutput
{
    public function __construct(
        public readonly int $bytes,
        /** The first {@see OutputCapture::EXCERPT_BYTES} bytes. */
        public readonly string $excerpt,
        /** `file:line` of the code that printed first; null when PHP does not tell. */
        public readonly ?string $origin,
        /**
         * Whether the endpoint kept all of it out of the response. False when request code removed the capture's
         * output buffer (an unbalanced `ob_end_*()`), or when earlier output sits in a buffer the endpoint cannot empty.
         */
        public readonly bool $discarded = true,
    ) {
    }

    /** @return array<string, int|string> the log context keys that describe it */
    public function logContext(): array
    {
        return [SdkLog::OUTPUT_BYTES => $this->bytes, SdkLog::OUTPUT_ORIGIN => $this->origin ?? 'unknown', SdkLog::OUTPUT_EXCERPT => $this->excerpt];
    }
}
