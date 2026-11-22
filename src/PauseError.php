<?php

declare(strict_types=1);

namespace Restate;

/**
 * Pauses the invocation instead of retrying it: the attempt ends and Restate keeps the invocation paused until someone
 * resumes it (`restate invocations resume <id>`, the UI, or the admin API's `PATCH /invocations/{id}/resume`, which can
 * also move it to a newer deployment). Use it for a failure that retrying cannot fix but a person can: a missing
 * approval, a misconfiguration, a bug that needs a new deployment.
 *
 * Thrown in a `Context::run()` callback, it ends the run without recording a result, whatever the run's retry policy;
 * once resumed, the run executes again. Thrown by a handler, it ends the attempt; once resumed, the handler runs again
 * from the start, replaying its journal. An {@see ErrorMapper} may return one for exceptions it classifies.
 *
 * Needs Restate 1.8 or newer (service protocol v7). On an older server the pause cannot be requested: the attempt fails
 * with error code 573 and a message saying so, and Restate retries it like any other failure.
 */
class PauseError extends \RuntimeException implements Exception
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
