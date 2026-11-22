<?php

declare(strict_types=1);

namespace Restate;

/**
 * The context of a shared virtual object handler: the object's key and read access to its state. Shared handlers
 * run concurrently with each other and with the object's exclusive handler.
 */
interface ObjectSharedContext extends Context
{
    public function key(): string;

    /**
     * The value stored under the state key, or null when there is none.
     *
     * @param Serde|class-string<Serde>|null $serde
     */
    public function get(string $key, Serde|string|null $serde = null): mixed;

    /** @return list<string> */
    public function stateKeys(): array;
}
