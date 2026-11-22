<?php

declare(strict_types=1);

namespace Restate\Internal;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Restate\Internal\Backend\InvocationInput;

/**
 * What the service registry builds a handler's context from: the operations of the current attempt and the request
 * data the context exposes, apart from the routing (service, handler, key) and the input bytes, which the registry
 * gets separately.
 *
 * The endpoint passes the {@see SuspensionGuard} as the operations, so the handler's context fails fast once the
 * attempt suspended.
 *
 * @internal
 */
final readonly class InvocationAttempt
{
    /**
     * @param list<array{0: string, 1: string}> $headers the invocation's headers
     * @param string $invocationId empty only in tests that do not care about it
     * @param list<array{0: string, 1: string}> $attemptHeaders the HTTP headers of this attempt's request
     * @param LoggerInterface $logger the endpoint's logger, which the handler's context logs to
     */
    public function __construct(
        public InvocationOperations $operations,
        public int $randomSeed = 0,
        public array $headers = [],
        public string $invocationId = '',
        public array $attemptHeaders = [],
        public ?string $idempotencyKey = null,
        public ?string $scope = null,
        public ?string $limitKey = null,
        public LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @param list<array{0: string, 1: string}> $attemptHeaders */
    public static function of(InvocationOperations $operations, InvocationInput $input, array $attemptHeaders, LoggerInterface $logger = new NullLogger()): self
    {
        return new self(
            $operations,
            $input->randomSeed,
            $input->headers,
            $input->invocationId,
            $attemptHeaders,
            $input->idempotencyKey,
            $input->scope,
            $input->limitKey,
            $logger,
        );
    }
}
