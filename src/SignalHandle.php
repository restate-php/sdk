<?php

declare(strict_types=1);

namespace Restate;

/**
 * A named signal of another invocation, to resolve or reject: `$ctx->invocation($id)->signal('approved')`.
 *
 * Signals are identified by the target invocation's id and a name, and need no registration: the invocation waiting
 * for the signal ({@see Context::signal()}) receives a delivery whether it arrives before or after it starts waiting.
 * Each delivery is received once, in the order Restate delivered them; sending the same signal twice delivers it
 * twice. Sending is journaled and does not wait for the target.
 *
 * {@see InvocationHandle::signal()} creates one; there is no public implementation to construct.
 */
interface SignalHandle
{
    /**
     * Resolves the signal with $value, encoded with $serde or the context's default serde.
     *
     * @param Serde|class-string<Serde>|null $serde
     */
    public function resolve(mixed $value = null, Serde|string|null $serde = null): void;

    /** Rejects the signal: the invocation waiting for it receives $failure as its {@see TerminalError}. */
    public function reject(TerminalError $failure): void;
}
