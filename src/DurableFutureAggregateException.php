<?php

declare(strict_types=1);

namespace Restate;

final class DurableFutureAggregateException extends \RuntimeException implements Exception
{
    /** @param array<array-key, \Throwable> $failures */
    public function __construct(private readonly array $failures, string $message = 'No durable future completed successfully.')
    {
        parent::__construct($message);
    }

    /** @return array<array-key, \Throwable> */
    public function failures(): array
    {
        return $this->failures;
    }
}
