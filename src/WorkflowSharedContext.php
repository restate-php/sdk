<?php

declare(strict_types=1);

namespace Restate;

/**
 * The context of a shared workflow handler: read access to the workflow's state and its durable promises.
 */
interface WorkflowSharedContext extends ObjectSharedContext
{
    /** @param Serde|class-string<Serde>|null $serde */
    public function promise(string $name, Serde|string|null $serde = null): DurablePromise;

    /** @param Serde|class-string<Serde>|null $serde */
    public function promiseFuture(string $name, Serde|string|null $serde = null): DurableFuture;
}
