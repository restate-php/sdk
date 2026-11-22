<?php

declare(strict_types=1);

namespace Restate\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Restate\Context;
use Restate\Internal\SdkLog;

/**
 * A PSR-3 logger for handler code: drops records while the handler replays journal entries of an earlier attempt, so
 * each line is logged once per invocation rather than once per attempt, and adds the invocation's
 * `restate.invocation.id` and `restate.invocation.target` to the context (keys the caller passes win).
 *
 * `Context::logger()` returns one on the endpoint's logger ({@see \Restate\EndpointBuilder::logger()}); construct one
 * yourself to wrap another logger.
 */
final class ReplayAwareLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private readonly Context $context,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<mixed> $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->context->isReplaying()) {
            return;
        }

        $this->logger->log($level, $message, $context + SdkLog::invocation($this->context->request()));
    }
}
