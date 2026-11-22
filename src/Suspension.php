<?php

declare(strict_types=1);

namespace Restate;

use Restate\Internal\SuspensionException;

/**
 * Recognises the SDK's suspension, the internal `\Error` that unwinds a handler when the invocation suspends.
 *
 * Handlers never need this. A {@see HandlerInterceptor} or {@see RunInterceptor} that catches `\Throwable` around
 * `$next` to record a failure uses it to tell a suspension (not a failure: Restate resumes the invocation later) from an
 * error. See docs/decisions/0013-suspension-is-not-an-exception.md.
 */
final class Suspension
{
    private function __construct()
    {
    }

    /** Whether $error is the SDK's suspension, or wraps one (as `previous`, at any depth). */
    public static function is(\Throwable $error): bool
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof SuspensionException) {
                return true;
            }
        }

        return false;
    }
}
