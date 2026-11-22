<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

/**
 * What proposing a retryable run failure did ({@see \Restate\Internal\InvocationOperations::proposeRunCompletionRetryable()}).
 * The values are the Extension Surface's names.
 *
 * @internal
 */
enum RunCompletionStatus: string
{
    /**
     * The run did not complete and the VM ended this attempt, which must stop as after a suspension: the retry policy
     * scheduled another attempt or, only under a policy that pauses once exhausted, the invocation pauses. The VM
     * cannot tell which (docs/decisions/0021-pause.md); nothing here depends on it.
     */
    case AttemptEnded = 'attempt_ended';

    /** The policy is exhausted: the failure is the run's terminal result; await the run and take it. */
    case Completed = 'completed';
}
