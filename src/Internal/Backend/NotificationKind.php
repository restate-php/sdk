<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

/**
 * What a completed handle's result is ({@see NotificationResult}). The values are the Extension Surface's names.
 *
 * @internal
 */
enum NotificationKind: string
{
    /** A value: a call's, attach's, awakeable's, run's or promise's result, or a state value. */
    case Success = 'success';

    /** No value: a sleep, a promise completion, an unset state key, a promise peek before completion. */
    case Void = 'void';

    /** A terminal failure. */
    case Failure = 'failure';

    /** A send's (or a call's) new invocation id. */
    case InvocationId = 'invocation_id';

    /** The state keys. */
    case StateKeys = 'state_keys';
}
