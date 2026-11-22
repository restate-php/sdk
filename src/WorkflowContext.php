<?php

declare(strict_types=1);

namespace Restate;

/**
 * The context of a workflow's `run` handler: everything a shared workflow handler can do, plus changing the
 * workflow's state.
 */
interface WorkflowContext extends WorkflowSharedContext, ObjectContext
{
}
