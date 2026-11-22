<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

use Restate\Internal\InvocationOperations;

/**
 * One request's VM: its input, output and response head, plus the operations a handler's context performs on it.
 *
 * @internal
 */
interface Invocation extends InvocationOperations
{
    public function responseHead(): ResponseHead;

    public function notifyInput(string $bytes): void;

    public function notifyInputClosed(): void;

    public function isReadyToExecute(): bool;

    public function input(): InvocationInput;

    public function writeOutputSuccess(string $bytes): void;

    /** @param array<array-key, string> $metadata */
    public function writeOutputFailure(int $code, string $message, array $metadata = []): void;

    /**
     * Ends the attempt with a retryable failure. `$retryAfterMillis` asks Restate to wait that long before the next
     * attempt instead of following the invocation retry policy.
     */
    public function notifyError(string $message, string $stacktrace = '', ?int $retryAfterMillis = null): void;

    /**
     * Ends the attempt asking Restate to pause the invocation instead of retrying it, until someone resumes it. Needs
     * service protocol v7 (Restate 1.8): below it the attempt fails with 573 instead, which Restate retries.
     */
    public function notifyPause(string $message, string $stacktrace = ''): void;

    public function end(): void;

    public function takeOutput(): ?string;

    /**
     * Releases the extension VM now instead of when this object is destroyed; every later call fails. Idempotent, and
     * never throws. `Endpoint::handleRequest()` calls it once the response is built.
     */
    public function free(): void;
}
