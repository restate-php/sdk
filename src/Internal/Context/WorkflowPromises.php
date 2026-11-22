<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use Restate\DurableFuture;
use Restate\DurablePromise;
use Restate\Serde;

/**
 * The durable promises of the two workflow contexts, which extend different object contexts.
 *
 * @internal
 */
trait WorkflowPromises
{
    public function promise(string $name, Serde|string|null $serde = null): DurablePromise
    {
        return new DurablePromiseImpl($this->operations(), $name, $this->serde($serde), $this->codec());
    }

    public function promiseFuture(string $name, Serde|string|null $serde = null): DurableFuture
    {
        return $this->promise($name, $serde)->future();
    }
}
