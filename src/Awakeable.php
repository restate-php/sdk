<?php

declare(strict_types=1);

namespace Restate;

/**
 * A durable callback: hand its {@see id()} to another system, which completes it through Restate (or another handler
 * through {@see Context::resolveAwakeable()} / {@see Context::rejectAwakeable()}), and wait for the value here.
 *
 * {@see Context::awakeable()} creates one; there is no public implementation to construct.
 */
interface Awakeable
{
    /** The id to complete the awakeable with. */
    public function id(): string;

    /**
     * Waits for the awakeable and returns its value, decoded with $serde or the serde it was created with. A rejected
     * awakeable throws its {@see TerminalError}.
     *
     * @param Serde|class-string<Serde>|null $serde
     */
    public function value(Serde|string|null $serde = null): mixed;
}
