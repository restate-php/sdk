<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

/**
 * What an await reported ({@see AwaitResult}). The values are the Extension Surface's names.
 *
 * @internal
 */
enum AwaitState: string
{
    /** The awaited handle, or the await tree, completed: take the result. */
    case Completed = 'any_completed';

    /** The invocation suspended; the attempt must end without output. */
    case Suspended = 'suspended';

    /** The VM needs more input, which a request/response attempt cannot deliver once the request body is read. */
    case ReadFromInput = 'read_from_input';

    /** The VM waits for a run that was proposed but not acknowledged. */
    case WaitingPendingRun = 'waiting_pending_run';

    /** The invocation received a cancel signal. */
    case CancelSignalReceived = 'cancel_signal_received';

    /** The awaited run must be executed now ({@see AwaitResult::$runHandle}). */
    case ExecuteRun = 'execute_run';
}
