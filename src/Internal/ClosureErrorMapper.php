<?php

declare(strict_types=1);

namespace Restate\Internal;

use Restate\ErrorMapper;
use Restate\PauseError;
use Restate\RetryableError;
use Restate\TerminalError;

/**
 * An {@see ErrorMapper} given as a closure.
 *
 * @internal
 */
final readonly class ClosureErrorMapper implements ErrorMapper
{
    /** @param \Closure(\Throwable): (TerminalError|RetryableError|PauseError|null) $map */
    private function __construct(private \Closure $map)
    {
    }

    /** @param ErrorMapper|\Closure(\Throwable): (TerminalError|RetryableError|PauseError|null) $mapper */
    public static function of(ErrorMapper|\Closure $mapper): ErrorMapper
    {
        return $mapper instanceof ErrorMapper ? $mapper : new self($mapper);
    }

    public function map(\Throwable $error): TerminalError|RetryableError|PauseError|null
    {
        return ($this->map)($error);
    }
}
