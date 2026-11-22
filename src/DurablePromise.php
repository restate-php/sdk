<?php

declare(strict_types=1);

namespace Restate;

/**
 * A workflow's named durable promise: any handler of the workflow can await, peek, resolve or reject it, once.
 *
 * {@see WorkflowSharedContext::promise()} returns one; there is no public implementation to construct. A $serde
 * argument overrides the serde the promise was created with for that one call.
 */
interface DurablePromise
{
    /**
     * Waits for the promise and returns its value. A rejected promise throws its {@see TerminalError}.
     *
     * @param Serde|class-string<Serde>|null $serde
     */
    public function value(Serde|string|null $serde = null): mixed;

    /**
     * The promise's value, or null while it is not completed. A rejected promise throws its {@see TerminalError}.
     *
     * @param Serde|class-string<Serde>|null $serde
     */
    public function peek(Serde|string|null $serde = null): mixed;

    /**
     * A future of the promise's value, to combine with other futures.
     *
     * @param Serde|class-string<Serde>|null $serde
     */
    public function future(Serde|string|null $serde = null): DurableFuture;

    /**
     * Resolves the promise; throws a {@see TerminalError} when it was already completed.
     *
     * @param Serde|class-string<Serde>|null $serde
     */
    public function resolve(mixed $value, Serde|string|null $serde = null): void;

    /** Rejects the promise; throws a {@see TerminalError} when it was already completed. */
    public function reject(TerminalError $failure): void;
}
