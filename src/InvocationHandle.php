<?php

declare(strict_types=1);

namespace Restate;

/**
 * Another invocation: one this handler sent ({@see Context::send()}), or one it refers to by id or attach target
 * ({@see Context::invocation()}, {@see Context::attach()}).
 *
 * Contexts create handles; there is no public implementation to construct.
 *
 * @template-covariant T = mixed the output as the handle's serde decodes it: `mixed` unless the handle comes from a typed
 *                             request
 */
interface InvocationHandle
{
    /**
     * The invocation's id. For a sent invocation, the first call waits until Restate assigned it. Throws a
     * \RuntimeException for a handle attached by idempotency key or workflow, whose id is not known.
     */
    public function id(): string;

    /**
     * Waits for the invocation to finish and returns its output, decoded with $serde or the handle's serde. A failed
     * invocation throws its {@see TerminalError}. Attaches to the invocation, like `attach()` in the official SDKs; for
     * a sent invocation it first waits for the id, like {@see id()}.
     *
     * @param Serde|class-string<Serde>|null $serde
     *
     * @return ($serde is null ? T : mixed)
     */
    public function output(Serde|string|null $serde = null): mixed;

    /**
     * The invocation's output if it has finished, or null while it is still running: does not wait for it to finish
     * (like the Java SDK's `getOutput()`). A failed invocation throws its {@see TerminalError}. Decoded with $serde or
     * the handle's serde. As with {@see DurablePromise::peek()}, null is also what an invocation that returned null
     * gives; use {@see output()} to wait.
     *
     * @param Serde|class-string<Serde>|null $serde
     *
     * @return ($serde is null ? T|null : mixed)
     */
    public function peekOutput(Serde|string|null $serde = null): mixed;

    /** Cancels the invocation. Needs its id, so it waits like {@see id()} for a sent invocation. */
    public function cancel(): void;

    /**
     * The invocation's signal $name, to resolve or reject. Sending it needs the invocation's id, so it waits like
     * {@see id()} for a sent invocation, and throws a \RuntimeException for a handle whose id is not known.
     */
    public function signal(string $name): SignalHandle;
}
