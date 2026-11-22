<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

/**
 * A new awakeable ({@see \Restate\Internal\InvocationOperations::sysAwakeable()}): the id others complete it with and
 * the handle its result arrives on.
 *
 * @internal
 */
final readonly class AwakeableHandle
{
    public function __construct(
        public string $id,
        public int $handle,
    ) {
    }
}
