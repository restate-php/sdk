<?php

declare(strict_types=1);

namespace Restate;

/**
 * The kind of a handler of a Virtual Object or a Workflow, as the discovery manifest names it. Handlers of a Basic
 * Service have no type.
 */
enum HandlerType: string
{
    /** A Virtual Object handler that runs alone per key and may write state. */
    case Exclusive = 'EXCLUSIVE';

    /** A handler that runs concurrently with others of its key and only reads state. */
    case Shared = 'SHARED';

    /** The `run` handler of a Workflow. */
    case Workflow = 'WORKFLOW';
}
