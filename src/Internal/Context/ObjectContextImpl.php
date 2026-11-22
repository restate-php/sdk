<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use Restate\ObjectContext;
use Restate\Serde;

/**
 * The {@see ObjectContext} of an exclusive virtual object handler.
 *
 * @internal
 */
class ObjectContextImpl extends ObjectSharedContextImpl implements ObjectContext
{
    public function set(string $key, mixed $value, Serde|string|null $serde = null): void
    {
        $this->operations()->setState($key, $this->encode($this->serde($serde)->serialize($value)));
    }

    public function clear(string $key): void
    {
        $this->operations()->clearState($key);
    }

    public function clearAll(): void
    {
        $this->operations()->clearAllState();
    }
}
