<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;

final readonly class InvocationRetryPolicy
{
    public const string ON_MAX_ATTEMPTS_PAUSE = 'PAUSE';
    public const string ON_MAX_ATTEMPTS_KILL = 'KILL';

    public function __construct(
        public readonly ?int $initialIntervalMillis = null,
        public readonly ?float $exponentiationFactor = null,
        public readonly ?int $maxIntervalMillis = null,
        public readonly ?int $maxAttempts = null,
        public readonly ?string $onMaxAttempts = null,
    ) {
        self::assertNullableDuration($initialIntervalMillis);
        self::assertNullableDuration($maxIntervalMillis);
        self::assertNullableMaxAttempts($maxAttempts);
        self::assertNullableExponentiationFactor($exponentiationFactor);
        self::assertNullableOnMaxAttempts($onMaxAttempts);
    }

    /** @return array<string, int|float|string> */
    public function manifestFields(): array
    {
        $fields = [];
        if ($this->initialIntervalMillis !== null) {
            $fields['retryPolicyInitialInterval'] = $this->initialIntervalMillis;
        }
        if ($this->maxIntervalMillis !== null) {
            $fields['retryPolicyMaxInterval'] = $this->maxIntervalMillis;
        }
        if ($this->maxAttempts !== null) {
            $fields['retryPolicyMaxAttempts'] = $this->maxAttempts;
        }
        if ($this->exponentiationFactor !== null) {
            $fields['retryPolicyExponentiationFactor'] = $this->exponentiationFactor;
        }
        if ($this->onMaxAttempts !== null) {
            $fields['retryPolicyOnMaxAttempts'] = $this->onMaxAttempts;
        }

        return $fields;
    }

    private static function assertNullableDuration(?int $millis): void
    {
        if ($millis !== null && $millis < 0) {
            throw new InvalidArgumentException('Invocation retry policy durations must be greater than or equal to 0 milliseconds.');
        }
    }

    private static function assertNullableMaxAttempts(?int $maxAttempts): void
    {
        if ($maxAttempts !== null && $maxAttempts < 0) {
            throw new InvalidArgumentException('Invocation retry policy max attempts must be greater than or equal to 0.');
        }
    }

    private static function assertNullableExponentiationFactor(?float $factor): void
    {
        if ($factor === null) {
            return;
        }

        if (!is_finite($factor)) {
            throw new InvalidArgumentException('Invocation retry policy exponentiation factor must be finite.');
        }

        if ($factor < 1.0) {
            throw new InvalidArgumentException('Invocation retry policy exponentiation factor must be greater than or equal to 1.0.');
        }
    }

    private static function assertNullableOnMaxAttempts(?string $onMaxAttempts): void
    {
        if ($onMaxAttempts !== null && !in_array($onMaxAttempts, [self::ON_MAX_ATTEMPTS_PAUSE, self::ON_MAX_ATTEMPTS_KILL], true)) {
            throw new InvalidArgumentException('Invocation retry policy on max attempts must be PAUSE or KILL.');
        }
    }
}
