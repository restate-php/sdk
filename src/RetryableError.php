<?php

declare(strict_types=1);

namespace Restate;

use Restate\Internal\TerminalMetadata;

/**
 * A failure worth retrying. Thrown in a `Context::run()` callback, it fails that attempt of the run, and the run's retry
 * policy decides whether and when it runs again; once the policy is exhausted, the run fails terminally with the
 * message, status code and metadata of the last failure. Thrown by a handler, it fails the attempt and Restate retries
 * the invocation.
 *
 * The status code is the exception code when it is a failure status (400–599, except 570, which Restate reserves for
 * journal mismatches), and 500 otherwise, including the default code 0.
 */
class RetryableError extends \RuntimeException implements Exception
{
    /** @var array<array-key, string> */
    private readonly array $metadata;

    /**
     * @param array<array-key, mixed> $metadata string values; numeric-string keys such as '404' are allowed (PHP stores them as ints)
     */
    public function __construct(
        string $message,
        private readonly ?int $retryAfterMillis = null,
        ?\Throwable $previous = null,
        int $code = 0,
        array $metadata = [],
    ) {
        if ($retryAfterMillis !== null && $retryAfterMillis < 0) {
            throw new \InvalidArgumentException('Retry-after delay must be greater than or equal to 0 milliseconds.');
        }

        if (!TerminalMetadata::isValid($metadata)) {
            throw new \InvalidArgumentException('Retryable error metadata must be a string-to-string map.');
        }

        $this->metadata = $metadata;

        parent::__construct($message, $code, $previous);
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
     * The metadata the terminal failure carries once a run's retry policy is exhausted. Keys are strings on the wire;
     * PHP holds numeric-string keys such as "404" as int keys.
     *
     * @return array<array-key, string>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
