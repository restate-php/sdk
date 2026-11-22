<?php

declare(strict_types=1);

namespace Restate\Internal;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Restate\Internal\Backend\CoreLogRecord;

/**
 * Forwards one invocation attempt's shared-core log events to the endpoint's logger (docs/decisions/0027-sdk-logging.md).
 *
 * The endpoint creates one per attempt and hands it to the backend, which creates the VM capturing events at
 * {@see $level} and forwards what it captured after every VM call. The endpoint sets the invocation's context once the
 * input names it; until then records carry what the request said.
 *
 * @internal
 */
final class CoreLogForwarder
{
    /** PSR-3 level => the `tracing` level that captures it and everything more severe. */
    private const array CORE_LEVELS = [
        LogLevel::EMERGENCY => 'error',
        LogLevel::ALERT => 'error',
        LogLevel::CRITICAL => 'error',
        LogLevel::ERROR => 'error',
        LogLevel::WARNING => 'warn',
        LogLevel::NOTICE => 'info',
        LogLevel::INFO => 'info',
        LogLevel::DEBUG => 'debug',
    ];

    /** `tracing` level => PSR-3 level. PSR-3 has nothing below debug. */
    private const array PSR_LEVELS = [
        'error' => LogLevel::ERROR,
        'warn' => LogLevel::WARNING,
        'info' => LogLevel::INFO,
        'debug' => LogLevel::DEBUG,
        'trace' => LogLevel::DEBUG,
    ];

    /** The record's `tracing` target (the Rust module that logged). */
    public const string TARGET = 'restate.core.target';

    /** Prefix of the record's other `tracing` fields. */
    public const string FIELD_PREFIX = 'restate.core.';

    /**
     * @param 'error'|'warn'|'info'|'debug' $level the `tracing` level to capture at
     * @param array<string, string> $context
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        public readonly string $level,
        private array $context = [],
    ) {
    }

    /**
     * The `tracing` level that captures the shared-core events a PSR-3 level asks for.
     *
     * @return 'error'|'warn'|'info'|'debug'
     *
     * @throws \InvalidArgumentException for a level PSR-3 does not define
     */
    public static function coreLevel(string $psrLevel): string
    {
        return self::CORE_LEVELS[$psrLevel] ?? throw new \InvalidArgumentException(sprintf(
            "Unknown PSR-3 log level '%s' for shared-core logs; use a Psr\\Log\\LogLevel constant.",
            $psrLevel,
        ));
    }

    /** @param array<string, string> $context the invocation's keys, once its input named it */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /** @param list<CoreLogRecord> $records */
    public function forward(array $records): void
    {
        foreach ($records as $record) {
            $context = $this->context + [self::TARGET => $record->target];
            foreach ($record->fields as $name => $value) {
                $context[self::FIELD_PREFIX . $name] ??= $value;
            }

            SdkLog::write($this->logger, self::PSR_LEVELS[$record->level], $record->message, $context);
        }
    }
}
