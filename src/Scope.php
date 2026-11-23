<?php

declare(strict_types=1);

namespace Restate;

use Restate\Internal\FlowControlKeys;

/**
 * Clients for calls and sends within one scope ({@see Context::scope()}): every target they build is
 * {@see Target::scoped()} to it.
 *
 * A scope is part of the target's identity (an object or workflow key, and an idempotency key, name a different
 * instance in each scope) and what Restate's concurrency rules apply to; a limit key on a request
 * ({@see ClientRequest::limitKey()}) narrows the limit within the scope. Needs service protocol v7 (Restate 1.8 or
 * newer).
 */
final readonly class Scope
{
    /**
     * @internal built by {@see Context::scope()}
     */
    public function __construct(
        private Context $context,
        private string $scope,
    ) {
        FlowControlKeys::assertScope($scope);
    }

    /** The scope's name. */
    public function name(): string
    {
        return $this->scope;
    }

    public function serviceClient(string $service): ServiceClient
    {
        return new ServiceClient($this->context, $service, $this->scope);
    }

    public function objectClient(string $object, string $key): ObjectClient
    {
        return new ObjectClient($this->context, $object, $key, $this->scope);
    }

    public function workflowClient(string $workflow, string $key): WorkflowClient
    {
        return new WorkflowClient($this->context, $workflow, $key, $this->scope);
    }
}
