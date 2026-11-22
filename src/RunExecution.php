<?php

declare(strict_types=1);

namespace Restate;

/**
 * What a {@see RunInterceptor} learns about the `Context::run()` callback it wraps. A run interceptor only runs when the
 * callback executes, so there is no replay state to report.
 */
final readonly class RunExecution
{
    /**
     * @param Request $request the request that started the invocation
     * @param string $name the run's name, as passed to `Context::run()`
     */
    public function __construct(
        public Request $request,
        public string $name,
    ) {
    }
}
