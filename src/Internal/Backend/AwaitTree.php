<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

use Restate\Internal\AwaitCombinator;

/**
 * What one {@see \Restate\Internal\InvocationOperations::doAwaitTree()} waits for: a combinator over handles and
 * nested trees. It is also the await point the VM reports to Restate when the invocation suspends.
 *
 * @internal
 */
final readonly class AwaitTree
{
    /** @var non-empty-list<int|AwaitTree> */
    public array $children;

    /** @param list<int|AwaitTree> $children */
    public function __construct(
        public AwaitCombinator $combinator,
        array $children,
    ) {
        if ($children === []) {
            throw new \InvalidArgumentException('An await tree combinator needs at least one child.');
        }

        $this->children = $children;
    }
}
