<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

/**
 * One log event of shared-core, captured by the extension for an invocation created with a log level (see
 * {@see \Restate\Internal\CoreLogForwarder}).
 *
 * @internal
 */
final readonly class CoreLogRecord
{
    /**
     * @param 'error'|'warn'|'info'|'debug'|'trace' $level the `tracing` level
     * @param string $target the Rust module that logged, e.g. `restate_sdk_shared_core::vm`
     * @param array<string, string> $fields the event's other fields, formatted
     */
    public function __construct(
        public string $level,
        public string $target,
        public string $message,
        public array $fields = [],
    ) {
    }
}
