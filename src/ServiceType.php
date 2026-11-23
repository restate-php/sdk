<?php

declare(strict_types=1);

namespace Restate;

/**
 * The kind of a service, as the discovery manifest names it.
 */
enum ServiceType: string
{
    /** A Basic Service: stateless handlers, no key. */
    case Service = 'SERVICE';

    /** A Virtual Object: keyed, with state and exclusive or shared handlers. */
    case VirtualObject = 'VIRTUAL_OBJECT';

    /** A Workflow: keyed, a `run` handler that runs once per key and shared handlers. */
    case Workflow = 'WORKFLOW';
}
