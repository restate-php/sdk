<?php

declare(strict_types=1);

namespace Restate;

/**
 * Classifies exceptions that are not a {@see TerminalError}, {@see RetryableError} or {@see PauseError}: thrown by a
 * handler (or a handler interceptor), or by a `Context::run()` callback (or a run interceptor).
 *
 * Return a {@see TerminalError} to fail the invocation (or the run) without retries, with its status code and
 * metadata; return a {@see RetryableError} to retry with its retry-after delay, status code and metadata; return a
 * {@see PauseError} to pause the invocation until someone resumes it (Restate 1.8 or newer); return `null` to leave the
 * exception as it is (retried, the default). Pass the original exception as `previous` to keep it in logs. The mapper
 * never sees a suspension, a `TerminalError`, a `RetryableError` or a `PauseError`. If it throws, that exception fails
 * the attempt.
 *
 * Configure it on the endpoint (`EndpointBuilder::errorMapper()`) and per service (`Service::errorMapper()`, …); a
 * service's mapper is asked first, the endpoint's when the service's returns `null`.
 */
interface ErrorMapper
{
    public function map(\Throwable $error): TerminalError|RetryableError|PauseError|null;
}
