<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;

/**
 * How often and how fast a failing `ctx.run` closure is retried before its failure becomes terminal — or, with
 * {@see OnMaxAttempts::Pause}, before the invocation pauses.
 *
 * @phpstan-import-type RetryPolicy from \Restate\Extension\Vm as ExtensionRetryPolicy
 */
final readonly class RunRetryPolicy
{
    private const int MAX_ATTEMPTS_UPPER_BOUND = 4294967295;

    /** @param ExtensionRetryPolicy $payload */
    private function __construct(private readonly array $payload)
    {
    }

    public static function none(): self
    {
        return new self(['type' => 'none']);
    }

    public static function infinite(): self
    {
        return new self(['type' => 'infinite']);
    }

    public static function fixedDelay(?int $intervalMillis = null, ?int $maxAttempts = null, ?int $maxDurationMillis = null, OnMaxAttempts $onMaxAttempts = OnMaxAttempts::Fail): self
    {
        self::assertNullableDuration($intervalMillis);
        self::assertNullableDuration($maxDurationMillis);
        self::assertNullableMaxAttempts($maxAttempts);

        return new self([
            'type' => 'fixed_delay',
            'interval_millis' => $intervalMillis,
            'max_attempts' => $maxAttempts,
            'max_duration_millis' => $maxDurationMillis,
            'on_max_attempts' => $onMaxAttempts->value,
        ]);
    }

    public static function exponential(int $initialDelayMillis = 100, float $factor = 2.0, ?int $maxDelayMillis = 2000, ?int $maxAttempts = null, ?int $maxDurationMillis = 50000, OnMaxAttempts $onMaxAttempts = OnMaxAttempts::Fail): self
    {
        self::assertDuration($initialDelayMillis);
        self::assertNullableDuration($maxDelayMillis);
        self::assertNullableDuration($maxDurationMillis);
        self::assertNullableMaxAttempts($maxAttempts);
        if (!is_finite($factor)) {
            throw new InvalidArgumentException('Retry policy exponentiation factor must be finite.');
        }

        if ($factor < 1.0) {
            throw new InvalidArgumentException('Retry policy exponentiation factor must be greater than or equal to 1.0.');
        }

        return new self([
            'type' => 'exponential',
            'initial_interval_millis' => $initialDelayMillis,
            'factor' => $factor,
            'max_interval_millis' => $maxDelayMillis,
            'max_attempts' => $maxAttempts,
            'max_duration_millis' => $maxDurationMillis,
            'on_max_attempts' => $onMaxAttempts->value,
        ]);
    }

    /**
     * @internal The policy as the Extension Surface takes it (`type` plus the fields of that type); only the extension
     *           backend converts it.
     *
     * @return ExtensionRetryPolicy
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    private static function assertDuration(int $millis): void
    {
        if ($millis < 0) {
            throw new InvalidArgumentException('Retry policy durations must be greater than or equal to 0 milliseconds.');
        }
    }

    private static function assertNullableDuration(?int $millis): void
    {
        if ($millis !== null) {
            self::assertDuration($millis);
        }
    }

    private static function assertNullableMaxAttempts(?int $maxAttempts): void
    {
        if ($maxAttempts !== null && $maxAttempts < 0) {
            throw new InvalidArgumentException('Retry policy max attempts must be greater than or equal to 0.');
        }

        if ($maxAttempts !== null && $maxAttempts > self::MAX_ATTEMPTS_UPPER_BOUND) {
            throw new InvalidArgumentException('Retry policy max attempts must fit within 32 bits.');
        }
    }
}
