<?php

declare(strict_types=1);

namespace Restate\Internal\Backend\Extension;

use Restate\AttachTarget;
use Restate\Extension\ExtensionException;
use Restate\Extension\Vm;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitTree;
use Restate\Internal\Backend\AwakeableHandle;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\Invocation as InvocationContract;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Backend\ResponseHead;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Internal\BackendErrorCategory;
use Restate\Internal\BackendException;
use Restate\Internal\CoreLogForwarder;
use Restate\Internal\ReplayStateProvider;
use Restate\Internal\TerminalMetadata;
use Restate\RunRetryPolicy;

/**
 * One invocation on the PHP extension: a thin adapter from the internal Invocation contract to the extension's
 * `Restate\Extension\Vm`, which this object owns. The VM is released on free(), or when this object is freed if that
 * comes first.
 *
 * Every `ExtensionException` the extension throws is rethrown as a BackendException with the same category and code and
 * the ExtensionException as its previous one. Arguments and results cross as PHP values; {@see SurfaceValues} converts
 * between them and the typed Backend Contract.
 *
 * Created with a {@see CoreLogForwarder}, the VM captures shared-core's log events at its level, and every call hands
 * what it captured to the forwarder before it returns or throws.
 *
 * @internal
 */
final class Invocation implements InvocationContract, ReplayStateProvider
{
    private ?Vm $vm;

    /** @param ?CoreLogForwarder $coreLogs where the shared-core log events the VM captured go after every call */
    public function __construct(Vm $vm, private readonly ?CoreLogForwarder $coreLogs = null)
    {
        $this->vm = $vm;
    }

    public function __clone()
    {
        $this->vm = null;

        throw new BackendException('Extension invocations cannot be cloned.');
    }

    /**
     * Releases the VM now instead of when this object is freed; every later call fails. The extension drops the Rust VM
     * itself (`Vm::free()`), so whatever still references the PHP object no longer keeps the VM's state alive.
     */
    public function free(): void
    {
        [$vm, $this->vm] = [$this->vm, null];

        try {
            $vm?->free();
        } catch (ExtensionException $e) {
            throw self::backendException($e);
        }
    }

    public function responseHead(): ResponseHead
    {
        return SurfaceValues::responseHead($this->withVm(fn (): array => $this->vm()->responseHead()));
    }

    public function notifyInput(string $bytes): void
    {
        $this->withVm(fn () => $this->vm()->notifyInput($bytes));
    }

    public function notifyInputClosed(): void
    {
        $this->withVm(fn () => $this->vm()->notifyInputClosed());
    }

    public function isReadyToExecute(): bool
    {
        return $this->withVm(fn (): bool => $this->vm()->isReadyToExecute());
    }

    public function isReplaying(): bool
    {
        return $this->withVm(fn (): bool => $this->vm()->isReplaying());
    }

    public function input(): InvocationInput
    {
        return SurfaceValues::invocationInput($this->withVm(fn (): array => $this->vm()->input()));
    }

    public function writeOutputSuccess(string $bytes): void
    {
        $this->withVm(fn () => $this->vm()->writeOutputSuccess($bytes));
    }

    /** @param array<array-key, string> $metadata */
    public function writeOutputFailure(int $code, string $message, array $metadata = []): void
    {
        $this->withVm(fn () => $this->vm()->writeOutputFailure($code, $message, TerminalMetadata::forBackend($metadata)));
    }

    public function notifyError(string $message, string $stacktrace = '', ?int $retryAfterMillis = null, int $code = 500): void
    {
        $this->withVm(fn () => $this->vm()->notifyError($code, $message, $stacktrace, $retryAfterMillis));
    }

    public function notifyPause(string $message, string $stacktrace = ''): void
    {
        $this->withVm(fn () => $this->vm()->notifyPause($message, $stacktrace));
    }

    public function end(): void
    {
        $this->withVm(fn () => $this->vm()->end());
    }

    public function takeOutput(): ?string
    {
        return $this->withVm(fn (): ?string => $this->vm()->takeOutput());
    }

    public function sysAwakeable(): AwakeableHandle
    {
        return SurfaceValues::awakeable($this->withVm(fn (): array => $this->vm()->sysAwakeable()));
    }

    public function sysResolveAwakeable(string $id, string $payload): void
    {
        $this->withVm(fn () => $this->vm()->completeAwakeableSuccess($id, $payload));
    }

    /** @param array<array-key, string> $metadata */
    public function sysRejectAwakeable(string $id, int $code, string $message, array $metadata = []): void
    {
        $this->withVm(fn () => $this->vm()->completeAwakeableFailure($id, $code, $message, TerminalMetadata::forBackend($metadata)));
    }

    public function sysSignal(string $name): int
    {
        return $this->withVm(fn (): int => $this->vm()->sysSignal($name));
    }

    public function sysResolveSignal(string $invocationId, string $name, string $payload): void
    {
        $this->withVm(fn () => $this->vm()->completeSignalSuccess($invocationId, $name, $payload));
    }

    /** @param array<array-key, string> $metadata */
    public function sysRejectSignal(string $invocationId, string $name, int $code, string $message, array $metadata = []): void
    {
        $this->withVm(fn () => $this->vm()->completeSignalFailure($invocationId, $name, $code, $message, TerminalMetadata::forBackend($metadata)));
    }

    public function sysAttachInvocation(AttachTarget $target): int
    {
        $target = SurfaceValues::attachTarget($target);

        return $this->withVm(fn (): int => $this->vm()->sysAttachInvocation($target));
    }

    public function sysGetInvocationOutput(AttachTarget $target): int
    {
        $target = SurfaceValues::attachTarget($target);

        return $this->withVm(fn (): int => $this->vm()->sysGetInvocationOutput($target));
    }

    public function sysGetPromise(string $key): int
    {
        return $this->withVm(fn (): int => $this->vm()->sysGetPromise($key));
    }

    public function sysPeekPromise(string $key): int
    {
        return $this->withVm(fn (): int => $this->vm()->sysPeekPromise($key));
    }

    public function sysResolvePromise(string $key, string $payload): int
    {
        return $this->withVm(fn (): int => $this->vm()->sysResolvePromise($key, $payload));
    }

    /** @param array<array-key, string> $metadata */
    public function sysRejectPromise(string $key, int $code, string $message, array $metadata = []): int
    {
        return $this->withVm(fn (): int => $this->vm()->sysRejectPromise($key, $code, $message, TerminalMetadata::forBackend($metadata)));
    }

    public function sysCancelInvocation(string $invocationId): void
    {
        $this->withVm(fn () => $this->vm()->sysCancelInvocation($invocationId));
    }

    public function sysCall(CallTarget $target, string $payload, ?string $idempotencyKey = null): int
    {
        $target = SurfaceValues::callTarget($target);

        return $this->withVm(fn (): int => $this->vm()->sysCall($target, $payload, $idempotencyKey));
    }

    public function sysSend(CallTarget $target, string $payload, ?int $delayMillis, ?string $idempotencyKey = null): int
    {
        $target = SurfaceValues::callTarget($target);

        return $this->withVm(fn (): int => $this->vm()->sysSend($target, $payload, $delayMillis, $idempotencyKey));
    }

    public function sysSleep(int $delayMillis): int
    {
        return $this->withVm(fn (): int => $this->vm()->sysSleep($delayMillis));
    }

    public function takeNotificationResult(int $handle): NotificationResult
    {
        return SurfaceValues::notificationResult($this->withVm(fn (): array => $this->vm()->takeNotificationResult($handle)));
    }

    public function sysRun(string $name): int
    {
        return $this->withVm(fn (): int => $this->vm()->sysRun($name));
    }

    public function doAwait(int $handle): AwaitResult
    {
        return SurfaceValues::awaitResult($this->withVm(fn (): array => $this->vm()->doAwait($handle)));
    }

    public function doAwaitTree(AwaitTree $tree): AwaitResult
    {
        $tree = SurfaceValues::awaitTree($tree);

        return SurfaceValues::awaitTreeResult($this->withVm(fn (): array => $this->vm()->doAwaitTree($tree)));
    }

    public function isCompleted(int $handle): bool
    {
        return $this->withVm(fn (): bool => $this->vm()->isCompleted($handle));
    }

    public function proposeRunCompletionSuccess(int $handle, string $bytes, ?RunRetryPolicy $retryPolicy = null): void
    {
        $retryPolicy = SurfaceValues::retryPolicy($retryPolicy);

        $this->withVm(fn () => $this->vm()->proposeRunSuccess($handle, $bytes, $retryPolicy));
    }

    /** @param array<array-key, string> $metadata */
    public function proposeRunCompletionFailure(int $handle, int $code, string $message, ?RunRetryPolicy $retryPolicy = null, array $metadata = []): void
    {
        $retryPolicy = SurfaceValues::retryPolicy($retryPolicy);

        $this->withVm(fn () => $this->vm()->proposeRunFailure($handle, $code, $message, TerminalMetadata::forBackend($metadata), $retryPolicy));
    }

    /** @param array<array-key, string> $metadata */
    public function proposeRunCompletionRetryable(int $handle, string $message, int $attemptDurationMillis, ?RunRetryPolicy $retryPolicy = null, ?int $retryAfterMillis = null, int $code = 500, array $metadata = []): RunCompletionStatus
    {
        $retryPolicy = SurfaceValues::retryPolicy($retryPolicy);

        return SurfaceValues::runCompletionStatus($this->withVm(fn (): array => $this->vm()->proposeRunRetryable($handle, $code, $message, TerminalMetadata::forBackend($metadata), $attemptDurationMillis, $retryPolicy, $retryAfterMillis)));
    }

    public function proposeRunCompletionPause(int $handle, string $message, int $attemptDurationMillis): void
    {
        $this->withVm(fn () => $this->vm()->proposeRunPause($handle, $message, $attemptDurationMillis));
    }

    public function sysGetState(string $key): int
    {
        return $this->withVm(fn (): int => $this->vm()->sysGetState($key));
    }

    public function sysGetStateKeys(): int
    {
        return $this->withVm(fn (): int => $this->vm()->sysGetStateKeys());
    }

    public function setState(string $key, string $bytes): void
    {
        $this->withVm(fn () => $this->vm()->setState($key, $bytes));
    }

    public function clearState(string $key): void
    {
        $this->withVm(fn () => $this->vm()->clearState($key));
    }

    public function clearAllState(): void
    {
        $this->withVm(fn () => $this->vm()->clearAllState());
    }

    /**
     * Runs one call on the VM ($call reaches it through {@see vm()}) and rethrows an ExtensionException as
     * BackendException.
     *
     * The Vm is never a function argument here, nor in $call: with `zend.exception_ignore_args` off (PHP's compiled
     * default) every exception's trace keeps the arguments of each frame, so an ExtensionException, the BackendException
     * chaining it, and a logger keeping either would otherwise keep the Vm alive after free().
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function withVm(\Closure $call): mixed
    {
        $this->vm();

        try {
            return $call();
        } catch (ExtensionException $e) {
            throw self::backendException($e);
        } finally {
            if ($this->coreLogs !== null) {
                $this->forwardCoreLogs($this->coreLogs);
            }
        }
    }

    /** @throws BackendException once the VM was freed */
    private function vm(): Vm
    {
        return $this->vm ?? throw new BackendException('Extension VM has already been freed.');
    }

    /**
     * Forwards what the VM logged during the call, also when the call failed (a journal mismatch is logged as it
     * fails). A VM that cannot hand its records over (it panicked) loses them; the call's own outcome stands.
     */
    private function forwardCoreLogs(CoreLogForwarder $coreLogs): void
    {
        try {
            $records = SurfaceValues::coreLogRecords($this->vm()->takeLogs());
        } catch (ExtensionException|BackendException) {
            return;
        }

        $coreLogs->forward($records);
    }

    /**
     * The BackendException an ExtensionException is rethrown as: same message, the shared-core code, the same category,
     * the ExtensionException as previous.
     */
    public static function backendException(ExtensionException $e): BackendException
    {
        return new BackendException($e->getMessage(), $e->getCode(), $e, BackendErrorCategory::fromExtension($e->category));
    }
}
