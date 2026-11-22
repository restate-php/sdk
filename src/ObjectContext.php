<?php

declare(strict_types=1);

namespace Restate;

/**
 * The context of an exclusive virtual object handler: it can also change the object's state. Restate runs one
 * exclusive handler per object key at a time.
 */
interface ObjectContext extends ObjectSharedContext
{
    /** @param Serde|class-string<Serde>|null $serde */
    public function set(string $key, mixed $value, Serde|string|null $serde = null): void;

    public function clear(string $key): void;

    public function clearAll(): void;
}
