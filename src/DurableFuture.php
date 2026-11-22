<?php

declare(strict_types=1);

namespace Restate;

/**
 * The result of a durable operation started by a handler context: a call, a timer, an awakeable, a workflow promise.
 *
 * Await one future with {@see awaitResult()}; combine several from the same context with {@see Context::all()},
 * {@see Context::race()}, {@see Context::any()}, {@see Context::allSettled()}, {@see Context::select()} and
 * {@see Context::waitCompleted()}. Contexts create futures; there is no public implementation to construct.
 *
 * @template-covariant T = mixed the result: `mixed` unless the future comes from a typed request
 */
interface DurableFuture
{
    /**
     * Waits for the operation and returns its result, or throws its failure (a {@see TerminalError} for a failed
     * operation). A second call returns or throws the same result without waiting again.
     *
     * @return T
     */
    public function awaitResult(): mixed;

    /**
     * Whether this future already took its result in the current attempt. It is not a non-consuming readiness probe:
     * a future whose operation completed but which nothing awaited yet reports false.
     */
    public function isCompleted(): bool;
}
