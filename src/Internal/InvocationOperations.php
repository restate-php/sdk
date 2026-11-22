<?php

declare(strict_types=1);

namespace Restate\Internal;

use Restate\AttachTarget;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitTree;
use Restate\Internal\Backend\AwakeableHandle;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\RunRetryPolicy;

/**
 * The operations a handler's context performs on its invocation: the Backend Contract's per-invocation half (see
 * docs/BACKEND_ARCHITECTURE.md). Arguments and results are typed values; payloads are bytes as the journal holds them.
 *
 * Every operation that produces a result returns a handle and never waits: callers drive it with doAwait() /
 * doAwaitTree() and take the result with takeNotificationResult() once it completed. {@see ResultDecoder} maps the
 * await states and decodes the results, for every operation, in one place.
 *
 * @internal
 */
interface InvocationOperations
{
    public function sysAwakeable(): AwakeableHandle;

    public function sysResolveAwakeable(string $id, string $payload): void;

    /** @param array<array-key, string> $metadata */
    public function sysRejectAwakeable(string $id, int $code, string $message, array $metadata = []): void;

    /** A handle on the next delivery of the named signal to this invocation; journals nothing. */
    public function sysSignal(string $name): int;

    public function sysResolveSignal(string $invocationId, string $name, string $payload): void;

    /** @param array<array-key, string> $metadata */
    public function sysRejectSignal(string $invocationId, string $name, int $code, string $message, array $metadata = []): void;

    public function sysAttachInvocation(AttachTarget $target): int;

    public function sysGetInvocationOutput(AttachTarget $target): int;

    public function sysGetPromise(string $key): int;

    public function sysPeekPromise(string $key): int;

    public function sysResolvePromise(string $key, string $payload): int;

    /** @param array<array-key, string> $metadata */
    public function sysRejectPromise(string $key, int $code, string $message, array $metadata = []): int;

    public function sysCancelInvocation(string $invocationId): void;

    public function sysCall(CallTarget $target, string $payload, ?string $idempotencyKey = null): int;

    public function sysSend(CallTarget $target, string $payload, ?int $delayMillis, ?string $idempotencyKey = null): int;

    public function sysSleep(int $delayMillis): int;

    /** The result of a completed handle, runs included; a handle's result can be taken once. */
    public function takeNotificationResult(int $handle): NotificationResult;

    public function sysRun(string $name): int;

    public function doAwait(int $handle): AwaitResult;

    /**
     * Awaits a combinator tree. Completed means the tree, or a combinator nested in it, resolved; the result then
     * lists the tree's handles that are completed ({@see AwaitResult::$completedHandles}), in the tree's depth-first
     * order, as isCompleted() on each would right after the await, so a combinator over n futures costs one call,
     * not n + 1. The other states are those of doAwait().
     */
    public function doAwaitTree(AwaitTree $tree): AwaitResult;

    /**
     * Whether an await has processed the handle's result and it has not been taken yet. The SDK's combinators read
     * this from doAwaitTree()'s result instead.
     */
    public function isCompleted(int $handle): bool;

    /** @param ?RunRetryPolicy $retryPolicy null means infinite */
    public function proposeRunCompletionSuccess(int $handle, string $bytes, ?RunRetryPolicy $retryPolicy = null): void;

    /** @param array<array-key, string> $metadata */
    public function proposeRunCompletionFailure(int $handle, int $code, string $message, ?RunRetryPolicy $retryPolicy = null, array $metadata = []): void;

    /**
     * Proposes a retryable failure of the run. Once the retry policy is exhausted it becomes the run's terminal result
     * with $code (500 unless it is 400–599 and not 570) and $metadata. $retryAfterMillis replaces the policy's delay
     * before the next attempt.
     *
     * @param array<array-key, string> $metadata
     */
    public function proposeRunCompletionRetryable(int $handle, string $message, int $attemptDurationMillis, ?RunRetryPolicy $retryPolicy = null, ?int $retryAfterMillis = null, int $code = 500, array $metadata = []): RunCompletionStatus;

    /**
     * Ends the run's attempt, and with it the invocation's, asking Restate to pause the invocation instead of retrying;
     * the run executes again once someone resumes the invocation. The attempt must then stop as after a suspension.
     * Needs service protocol v7 (Restate 1.8): below it this throws a {@see BackendException} (protocol, 573) and the
     * attempt fails, for Restate to retry.
     */
    public function proposeRunCompletionPause(int $handle, string $message, int $attemptDurationMillis): void;

    public function sysGetState(string $key): int;

    public function sysGetStateKeys(): int;

    public function setState(string $key, string $bytes): void;

    public function clearState(string $key): void;

    public function clearAllState(): void;
}
