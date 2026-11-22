<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

use Restate\Target;

/**
 * Where a call or send goes ({@see \Restate\Internal\InvocationOperations::sysCall()} / `sysSend()`): the target
 * handler (with its scope, if any), the headers the callee receives, and the limit key within the scope.
 *
 * @internal
 */
final readonly class CallTarget
{
    /**
     * @param list<array{0: string, 1: string}> $headers normalized ({@see \Restate\Internal\RequestHeaders::normalize()})
     * @param ?string $limitKey validated, and only with a scoped target ({@see \Restate\Internal\FlowControlKeys})
     */
    public function __construct(
        public Target $target,
        public array $headers = [],
        public ?string $limitKey = null,
    ) {
    }
}
