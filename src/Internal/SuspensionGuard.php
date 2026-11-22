<?php

declare(strict_types=1);

namespace Restate\Internal;

use Restate\AttachTarget;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\AwaitTree;
use Restate\Internal\Backend\AwakeableHandle;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\RunRetryPolicy;

/**
 * Wraps the invocation operations a handler's context uses and fails fast once the invocation suspended.
 *
 * The guard notices a suspension when an await reports it, when a backend operation throws
 * {@see SuspensionException}, or when the core ended the attempt at a `Context::run` failure (a retry was scheduled, or
 * the invocation pauses). From then on every operation throws
 * {@see SuspensionException} without reaching the backend, so handler code that caught the suspension cannot keep
 * issuing journal commands, and the endpoint can tell that the attempt suspended whatever the handler did next.
 *
 * @internal
 */
final class SuspensionGuard implements InvocationOperations, ReplayStateProvider
{
    private const string SUSPENDED_MESSAGE = 'The invocation is suspended; no context operation can run in this attempt. Do not catch \Throwable around context calls.';

    private bool $suspended = false;

    public function __construct(private readonly InvocationOperations $operations)
    {
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    public function isReplaying(): bool
    {
        return $this->operations instanceof ReplayStateProvider && $this->operations->isReplaying();
    }

    public function sysAwakeable(): AwakeableHandle
    {
        return $this->guard(fn () => $this->operations->sysAwakeable());
    }

    public function sysResolveAwakeable(string $id, string $payload): void
    {
        $this->guard(fn () => $this->operations->sysResolveAwakeable($id, $payload));
    }

    public function sysRejectAwakeable(string $id, int $code, string $message, array $metadata = []): void
    {
        $this->guard(fn () => $this->operations->sysRejectAwakeable($id, $code, $message, $metadata));
    }

    public function sysSignal(string $name): int
    {
        return $this->guard(fn () => $this->operations->sysSignal($name));
    }

    public function sysResolveSignal(string $invocationId, string $name, string $payload): void
    {
        $this->guard(fn () => $this->operations->sysResolveSignal($invocationId, $name, $payload));
    }

    public function sysRejectSignal(string $invocationId, string $name, int $code, string $message, array $metadata = []): void
    {
        $this->guard(fn () => $this->operations->sysRejectSignal($invocationId, $name, $code, $message, $metadata));
    }

    public function sysAttachInvocation(AttachTarget $target): int
    {
        return $this->guard(fn () => $this->operations->sysAttachInvocation($target));
    }

    public function sysGetInvocationOutput(AttachTarget $target): int
    {
        return $this->guard(fn () => $this->operations->sysGetInvocationOutput($target));
    }

    public function sysGetPromise(string $key): int
    {
        return $this->guard(fn () => $this->operations->sysGetPromise($key));
    }

    public function sysPeekPromise(string $key): int
    {
        return $this->guard(fn () => $this->operations->sysPeekPromise($key));
    }

    public function sysResolvePromise(string $key, string $payload): int
    {
        return $this->guard(fn () => $this->operations->sysResolvePromise($key, $payload));
    }

    public function sysRejectPromise(string $key, int $code, string $message, array $metadata = []): int
    {
        return $this->guard(fn () => $this->operations->sysRejectPromise($key, $code, $message, $metadata));
    }

    public function sysCancelInvocation(string $invocationId): void
    {
        $this->guard(fn () => $this->operations->sysCancelInvocation($invocationId));
    }

    public function sysCall(CallTarget $target, string $payload, ?string $idempotencyKey = null): int
    {
        return $this->guard(fn () => $this->operations->sysCall($target, $payload, $idempotencyKey));
    }

    public function sysSend(CallTarget $target, string $payload, ?int $delayMillis, ?string $idempotencyKey = null): int
    {
        return $this->guard(fn () => $this->operations->sysSend($target, $payload, $delayMillis, $idempotencyKey));
    }

    public function sysSleep(int $delayMillis): int
    {
        return $this->guard(fn () => $this->operations->sysSleep($delayMillis));
    }

    public function takeNotificationResult(int $handle): NotificationResult
    {
        return $this->guard(fn () => $this->operations->takeNotificationResult($handle));
    }

    public function sysRun(string $name): int
    {
        return $this->guard(fn () => $this->operations->sysRun($name));
    }

    public function doAwait(int $handle): AwaitResult
    {
        $state = $this->guard(fn () => $this->operations->doAwait($handle));
        if ($state->state === AwaitState::Suspended) {
            $this->suspended = true;
        }

        return $state;
    }

    public function doAwaitTree(AwaitTree $tree): AwaitResult
    {
        $state = $this->guard(fn () => $this->operations->doAwaitTree($tree));
        if ($state->state === AwaitState::Suspended) {
            $this->suspended = true;
        }

        return $state;
    }

    public function isCompleted(int $handle): bool
    {
        return $this->guard(fn () => $this->operations->isCompleted($handle));
    }

    public function proposeRunCompletionSuccess(int $handle, string $bytes, ?RunRetryPolicy $retryPolicy = null): void
    {
        $this->guard(fn () => $this->operations->proposeRunCompletionSuccess($handle, $bytes, $retryPolicy));
    }

    public function proposeRunCompletionFailure(int $handle, int $code, string $message, ?RunRetryPolicy $retryPolicy = null, array $metadata = []): void
    {
        $this->guard(fn () => $this->operations->proposeRunCompletionFailure($handle, $code, $message, $retryPolicy, $metadata));
    }

    public function proposeRunCompletionRetryable(int $handle, string $message, int $attemptDurationMillis, ?RunRetryPolicy $retryPolicy = null, ?int $retryAfterMillis = null, int $code = 500, array $metadata = []): RunCompletionStatus
    {
        $status = $this->guard(fn () => $this->operations->proposeRunCompletionRetryable($handle, $message, $attemptDurationMillis, $retryPolicy, $retryAfterMillis, $code, $metadata));
        if ($status === RunCompletionStatus::AttemptEnded) {
            // The core ended the attempt (to retry the run later, or to pause); it is over just as after a suspension.
            $this->suspended = true;
        }

        return $status;
    }

    public function proposeRunCompletionPause(int $handle, string $message, int $attemptDurationMillis): void
    {
        $this->guard(fn () => $this->operations->proposeRunCompletionPause($handle, $message, $attemptDurationMillis));
        // The core ended the attempt for Restate to pause the invocation; it is over just as after a suspension.
        $this->suspended = true;
    }

    public function sysGetState(string $key): int
    {
        return $this->guard(fn () => $this->operations->sysGetState($key));
    }

    public function sysGetStateKeys(): int
    {
        return $this->guard(fn () => $this->operations->sysGetStateKeys());
    }

    public function setState(string $key, string $bytes): void
    {
        $this->guard(fn () => $this->operations->setState($key, $bytes));
    }

    public function clearState(string $key): void
    {
        $this->guard(fn () => $this->operations->clearState($key));
    }

    public function clearAllState(): void
    {
        $this->guard(fn () => $this->operations->clearAllState());
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function guard(\Closure $operation): mixed
    {
        if ($this->suspended) {
            throw new SuspensionException(self::SUSPENDED_MESSAGE);
        }

        try {
            return $operation();
        } catch (SuspensionException $e) {
            $this->suspended = true;

            throw $e;
        }
    }
}
