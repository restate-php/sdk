<?php

declare(strict_types=1);

namespace Restate\Internal;

use Restate\Internal\Backend\AwaitTree;

/**
 * The combinators of an await tree ({@see InvocationOperations::doAwaitTree()}): what the VM waits for before it
 * reports progress, and what it reports to Restate as the await point when the invocation suspends.
 *
 * @internal
 */
enum AwaitCombinator: string
{
    /** Resolves when the first child completes, successfully or not. */
    case FirstCompleted = 'first_completed';

    /** Resolves when every child completed, successfully or not. */
    case AllCompleted = 'all_completed';

    /** Resolves on the first child that succeeds, or once every child failed. */
    case FirstSucceededOrAllFailed = 'first_succeeded_or_all_failed';

    /** Resolves once every child succeeded, or on the first child that fails. */
    case AllSucceededOrFirstFailed = 'all_succeeded_or_first_failed';

    /** @param non-empty-list<int> $handles */
    public function tree(array $handles): AwaitTree
    {
        return new AwaitTree($this, $handles);
    }
}
