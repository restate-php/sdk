<?php

declare(strict_types=1);

namespace Restate;

use Restate\Internal\TerminalMetadata;

/**
 * A failure worth retrying. Thrown in a `Context::run()` callback, it fails that attempt of the run, and the run's retry
 * policy decides whether and when it runs again; once the policy is exhausted, the run fails terminally with the
 * message, status code and metadata of the last failure. Thrown by a handler, it fails the attempt with its status code
 * and Restate retries the invocation; the attempt's failure carries no metadata, which only a run's terminal failure
 * has.
 *
 * The status code is a failure status, 400–599, as for {@see TerminalError}, except 570, which Restate reserves for
 * journal mismatches.
 */
class RetryableError extends \RuntimeException implements Exception
{
    /** @var array<array-key, string> */
    private readonly array $metadata;

    /**
     * @param array<array-key, mixed> $metadata string values; numeric-string keys such as '404' are allowed (PHP stores them as ints)
     * @param ?\Throwable $previous the exception this one classifies, for example in an {@see ErrorMapper}
     * @param ?int $retryAfterMillis how long Restate waits before the next attempt; see {@see retryAfterMillis()}
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 500,
        array $metadata = [],
        ?\Throwable $previous = null,
        private readonly ?int $retryAfterMillis = null,
    ) {
        if ($statusCode < 400 || $statusCode > 599) {
            throw new \InvalidArgumentException('Retryable status code must be between 400 and 599.');
        }

        if ($statusCode === 570) {
            throw new \InvalidArgumentException('Retryable status code must not be 570, which Restate reserves for journal mismatches.');
        }

        if ($retryAfterMillis !== null && $retryAfterMillis < 0) {
            throw new \InvalidArgumentException('Retry-after delay must be greater than or equal to 0 milliseconds.');
        }

        if (!TerminalMetadata::isValid($metadata)) {
            throw new \InvalidArgumentException('Retryable error metadata must be a string-to-string map.');
        }

        $this->metadata = $metadata;

        parent::__construct($message, $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * How long Restate waits before the next attempt, instead of the retry policy's interval. The policy's attempt and
     * duration limits still apply. `null` leaves the delay to the policy.
     */
    public function retryAfterMillis(): ?int
    {
        return $this->retryAfterMillis;
    }

    /**
     * The metadata the terminal failure carries once a run's retry policy is exhausted; a handler's failed attempt
     * carries none. Keys are strings on the wire; PHP holds numeric-string keys such as "404" as int keys.
     *
     * @return array<array-key, string>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
