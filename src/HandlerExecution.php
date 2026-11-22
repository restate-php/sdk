<?php

declare(strict_types=1);

namespace Restate;

/**
 * What a {@see HandlerInterceptor} learns about the attempt it wraps.
 */
final readonly class HandlerExecution
{
    /** @var \Closure(): bool */
    private \Closure $isReplaying;

    /**
     * @param Request $request the request that started the invocation, with the current attempt's headers (for example
     *                         the W3C trace context in {@see Request::$attemptHeaders})
     * @param \Closure(): bool $isReplaying
     */
    public function __construct(
        public Request $request,
        \Closure $isReplaying,
    ) {
        $this->isReplaying = $isReplaying;
    }

    /**
     * Whether the handler is currently replaying journal entries of earlier attempts, rather than doing new work. It
     * changes while the handler runs: true at the start of an attempt that resumes a journal, false once the handler
     * has passed the journal's end. Use it to suppress duplicate logs, span events or metrics.
     */
    public function isReplaying(): bool
    {
        return ($this->isReplaying)();
    }
}
