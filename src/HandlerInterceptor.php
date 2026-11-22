<?php

declare(strict_types=1);

namespace Restate;

/**
 * A Runtime Execution Hook around every attempt of a handler: tracing, metrics, logging, scoping per-invocation state.
 *
 * {@see interceptHandler()} runs once per invocation attempt, also on attempts that replay the journal
 * ({@see HandlerExecution::isReplaying()}). `$next` runs the rest of the chain and then the handler (creating the
 * service instance when the service class is bound with a factory or container, decoding the input, calling the
 * handler, encoding the output); it returns nothing, so an interceptor cannot change the handler's result.
 *
 * The rules, which the SDK enforces:
 *
 * - Call `$next` exactly once. Returning without calling it, or calling it twice, fails the attempt with a
 *   `\LogicException` (the invocation is retried).
 * - Failures pass through. If `$next` throws and the interceptor returns normally, the SDK rethrows what `$next`
 *   threw: an interceptor cannot turn a failure into a success. It may throw another exception instead, for example a
 *   {@see TerminalError}, which then is the attempt's outcome (and goes to the {@see ErrorMapper}).
 * - A suspension cannot be stopped. When the invocation suspends, `$next` throws an internal `\Error`
 *   ({@see Suspension::is()} recognises it); whatever the interceptor does with it, the attempt ends as a suspension.
 *   Use `finally`, or catch `\Throwable` only to observe and rethrow.
 * - Do not use the handler's context here; the interceptor does not get one, and journal operations outside the
 *   handler would make the journal depend on interceptor configuration.
 */
interface HandlerInterceptor
{
    /** @param \Closure(): void $next */
    public function interceptHandler(HandlerExecution $execution, \Closure $next): void;
}
