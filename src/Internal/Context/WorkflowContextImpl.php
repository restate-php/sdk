<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use Restate\WorkflowContext;

/**
 * The {@see WorkflowContext} of a workflow's `run` handler.
 *
 * @internal
 */
final class WorkflowContextImpl extends ObjectContextImpl implements WorkflowContext
{
    use WorkflowPromises;
}
