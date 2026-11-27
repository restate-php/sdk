<?php

declare(strict_types=1);

namespace Restate;

/**
 * What a `ctx.run` retry policy ({@see RunRetryPolicy::fixedDelay()}, {@see RunRetryPolicy::exponential()}) does once
 * its attempts or its duration are used up.
 */
enum OnMaxAttempts: string
{
    /** The last failure becomes the run's terminal failure, with its message, status code and metadata (the default). */
    case Fail = 'fail';

    /**
     * The invocation pauses instead, as if the run had thrown a {@see PauseError}: the run has no result and executes
     * again once someone resumes the invocation.
     */
    case Pause = 'pause';
}
