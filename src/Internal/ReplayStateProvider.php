<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * Implemented by invocation operations that know whether the handler is replaying journal entries.
 *
 * @internal
 */
interface ReplayStateProvider
{
    public function isReplaying(): bool;
}
