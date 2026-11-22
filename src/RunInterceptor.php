<?php

declare(strict_types=1);

namespace Restate;

/**
 * A Runtime Execution Hook around the execution of a `Context::run()` callback (including `Context::now()`, which is
 * the run `date.now`).
 *
 * {@see interceptRun()} runs only when the callback executes, never when the run's result is replayed from the journal,
 * so a run interceptor sees each side effect exactly as often as it actually happens. `$next` runs the rest of the
 * chain and then the callback; it returns nothing, so an interceptor cannot change the value the run records.
 *
 * The rules of {@see HandlerInterceptor} apply: call `$next` exactly once, a failure of `$next` cannot be swallowed (the
 * interceptor may throw another exception instead, which is then classified as the run's failure: a
 * {@see TerminalError} fails the run, anything else is retried under the run's retry policy, after the
 * {@see ErrorMapper}), and a suspension always passes through.
 */
interface RunInterceptor
{
    /** @param \Closure(): void $next */
    public function interceptRun(RunExecution $execution, \Closure $next): void;
}
