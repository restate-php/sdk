<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use Restate\WorkflowSharedContext;

/**
 * The {@see WorkflowSharedContext} of a shared workflow handler.
 *
 * @internal
 */
final class WorkflowSharedContextImpl extends ObjectSharedContextImpl implements WorkflowSharedContext
{
    use WorkflowPromises;
}
