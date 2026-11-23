<?php

declare(strict_types=1);

namespace Restate;

/**
 * One workflow run (one key), from a handler: submit its `run` handler, attach to it, and call its shared handlers.
 */
final readonly class WorkflowClient
{
    /**
     * @param ?string $scope the scope its targets are in ({@see Context::scope()}); null for none
     *
     * @internal built by {@see Context::workflowClient()} and {@see Scope::workflowClient()}
     */
    public function __construct(
        private readonly Context $context,
        private readonly string $workflow,
        private readonly string $key,
        private readonly ?string $scope = null,
    ) {
    }

    /**
     * Sends the workflow's `run` handler without waiting for it. A workflow runs once per key: Restate does not start a
     * second run for the same key. For headers, a delay, an idempotency key or another serde, pass SendOptions or use
     * `request('run', $input)`.
     */
    public function submit(mixed $input = null, ?SendOptions $options = null): InvocationHandle
    {
        return $this->request('run', $input)->send($options);
    }

    /**
     * The workflow's run, to wait for its result ({@see InvocationHandle::output()}) or read it without waiting
     * ({@see InvocationHandle::peekOutput()}); the same as `$ctx->attach(AttachTarget::workflow(…))`.
     *
     * @param Serde|class-string<Serde>|null $serde decodes the result; the context's default serde when omitted
     */
    public function attach(Serde|string|null $serde = null): InvocationHandle
    {
        return $this->context->attach(AttachTarget::workflow($this->workflow, $this->key, $this->scope), $serde);
    }

    /** A request to one of the workflow's handlers: `run`, or a shared handler such as a signal or a query. */
    public function request(string $handler, mixed $payload = null): ClientRequest
    {
        $target = Target::workflow($this->workflow, $this->key, $handler);

        return new ClientRequest($this->context, $this->scope === null ? $target : $target->scoped($this->scope), $payload);
    }
}
