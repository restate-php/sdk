<?php

declare(strict_types=1);

namespace Restate;

/**
 * What every handler can do through Restate: call and send to other handlers, run durable side effects, sleep,
 * create and complete awakeables, wait for and send signals, and draw deterministic random values.
 *
 * Service handlers receive a `Context`. Virtual object and workflow handlers receive one of its sub-interfaces,
 * which add access to the object's key and state ({@see ObjectSharedContext}, {@see ObjectContext}) and to the
 * workflow's durable promises ({@see WorkflowSharedContext}, {@see WorkflowContext}).
 *
 * The SDK creates the context for each invocation attempt; there is no public implementation to construct.
 */
interface Context
{
    public function serviceClient(string $service): ServiceClient;

    public function objectClient(string $object, string $key): ObjectClient;

    public function workflowClient(string $workflow, string $key): WorkflowClient;

    /**
     * Clients whose calls and sends go to targets within $scope ({@see Target::scoped()}): a scope is part of the
     * target's identity, and what Restate's concurrency rules apply to. A call or send within a scope may add a limit
     * key ({@see ClientRequest::limitKey()}). Needs service protocol v7 (Restate 1.8 or newer).
     *
     * ```php
     * $ctx->scope('tenant-1')->serviceClient('Billing')->request('charge', $order)->limitKey('user-42')->call();
     * ```
     */
    public function scope(string $scope): Scope;

    /** The seed of the deterministic random values, derived from the invocation id. */
    public function randomSeed(): int;

    /** The request that started this invocation: its id, target, headers, raw input, idempotency key, scope, limit key. */
    public function request(): Request;

    /** Whether the handler is replaying journal entries from an earlier attempt. */
    public function isReplaying(): bool;

    /**
     * A logger for handler code: the endpoint's logger ({@see EndpointBuilder::logger()}; none configured: records go
     * nowhere) wrapped in a {@see Logging\ReplayAwareLogger}, which drops records while the handler replays and adds
     * the invocation's id and target to the context.
     */
    public function logger(): \Psr\Log\LoggerInterface;

    /** A deterministic random integer in [$min, $max]. */
    public function randInt(int $min, int $max): int;

    /** Deterministic random bytes. */
    public function randBytes(int $length): string;

    /** A deterministic random version 4 UUID. */
    public function uuid(): string;

    /** The current time in Unix epoch milliseconds, recorded in the journal so replay sees the same value. */
    public function now(): int;

    /** @param Serde|class-string<Serde>|null $serde */
    public function awakeable(Serde|string|null $serde = null): Awakeable;

    /**
     * @param Serde|class-string<Serde>|null $serde
     * @return array{id: string, future: DurableFuture}
     */
    public function awakeableFuture(Serde|string|null $serde = null): array;

    /** @param Serde|class-string<Serde>|null $serde */
    public function resolveAwakeable(string $id, mixed $value = null, Serde|string|null $serde = null): void;

    public function rejectAwakeable(string $id, TerminalError $failure): void;

    /**
     * A future of the next delivery of the signal $name to this invocation, decoded with $serde or the context's
     * default serde; a rejected signal fails it with its {@see TerminalError}.
     *
     * Another handler sends it with `$ctx->invocation($id)->signal($name)` ({@see InvocationHandle::signal()}), using
     * this invocation's id (`$ctx->request()->id`). A delivery that arrived before the handler started waiting is not
     * lost, and each delivery is received by one call, in order. Waiting journals nothing. Combine the future with
     * {@see all()}, {@see race()}, {@see select()} and the other combinators, for example to wait with a timeout.
     *
     * @param Serde|class-string<Serde>|null $serde
     */
    public function signal(string $name, Serde|string|null $serde = null): DurableFuture;

    /**
     * A handle on another invocation, by its id.
     *
     * @param Serde|class-string<Serde>|null $serde
     */
    public function invocation(string $invocationId, Serde|string|null $serde = null): InvocationHandle;

    /** @param Serde|class-string<Serde>|null $serde */
    public function attach(AttachTarget $target, Serde|string|null $serde = null): InvocationHandle;

    /**
     * @param Serde|class-string<Serde>|null $inputSerde
     * @param Serde|class-string<Serde>|null $outputSerde
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     * @param ?string $limitKey the concurrency limit key within the target's scope ({@see Target::scoped()}): one or two
     *                          levels separated by `/`, for example `user-42` or `eu/user-42`
     */
    public function callFuture(Target $target, mixed $payload, ?string $idempotencyKey = null, Serde|string|null $inputSerde = null, Serde|string|null $outputSerde = null, array $headers = [], ?string $limitKey = null): DurableFuture;

    /**
     * @param Serde|class-string<Serde>|null $inputSerde
     * @param Serde|class-string<Serde>|null $outputSerde
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     * @param ?string $limitKey the concurrency limit key within the target's scope ({@see Target::scoped()}): one or two
     *                          levels separated by `/`, for example `user-42` or `eu/user-42`
     */
    public function call(Target $target, mixed $payload, ?string $idempotencyKey = null, Serde|string|null $inputSerde = null, Serde|string|null $outputSerde = null, array $headers = [], ?string $limitKey = null): mixed;

    public function genericCall(Target $target, mixed $payload, ?CallOptions $options = null): mixed;

    /**
     * @param Serde|class-string<Serde>|null $inputSerde
     * @param Serde|class-string<Serde>|null $outputSerde
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     * @param ?string $limitKey the concurrency limit key within the target's scope ({@see Target::scoped()}): one or two
     *                          levels separated by `/`, for example `user-42` or `eu/user-42`
     */
    public function send(Target $target, mixed $payload, ?string $idempotencyKey = null, Serde|string|null $inputSerde = null, Serde|string|null $outputSerde = null, array $headers = [], ?string $limitKey = null): InvocationHandle;

    /**
     * @param Serde|class-string<Serde>|null $inputSerde
     * @param Serde|class-string<Serde>|null $outputSerde
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     * @param ?string $limitKey the concurrency limit key within the target's scope ({@see Target::scoped()}): one or two
     *                          levels separated by `/`, for example `user-42` or `eu/user-42`
     */
    public function sendAfter(Target $target, mixed $payload, int $delayMillis, ?string $idempotencyKey = null, Serde|string|null $inputSerde = null, Serde|string|null $outputSerde = null, array $headers = [], ?string $limitKey = null): InvocationHandle;

    public function genericSend(Target $target, mixed $payload, ?SendOptions $options = null): InvocationHandle;

    public function sleep(int $delayMillis): void;

    public function sleepFuture(int $delayMillis): DurableFuture;

    /**
     * Waits until one of the futures completes and returns its key, without taking its result.
     *
     * Like the other combinators below, it takes futures this context created, keyed as you like; a key is returned
     * or kept as given.
     *
     * @param array<array-key, DurableFuture> $futures
     */
    public function select(array $futures): int|string;

    /**
     * Waits until every future succeeds and returns their values in the original key order.
     *
     * The first failure is thrown: among futures already resolved, in key order; otherwise the first to complete (the
     * VM processes results in arrival order and stops at the first failure, so at most one failure is in).
     *
     * @param array<array-key, DurableFuture> $futures
     * @return array<array-key, mixed>
     */
    public function all(array $futures): array;

    /**
     * Waits for the first future to complete and returns or throws its result.
     *
     * @param array<array-key, DurableFuture> $futures
     */
    public function race(array $futures): mixed;

    /**
     * Waits for the first future to succeed, skipping failures until every future failed; then throws a
     * {@see DurableFutureAggregateException} with the failures by key, in the order the futures completed.
     *
     * @param array<array-key, DurableFuture> $futures
     */
    public function any(array $futures): mixed;

    /**
     * Waits for every future to settle and returns one row per key: `['status' => 'fulfilled', 'value' => …]` or
     * `['status' => 'rejected', 'reason' => \Throwable]`.
     *
     * @param array<array-key, DurableFuture> $futures
     * @return array<array-key, array{status: 'fulfilled', value: mixed}|array{status: 'rejected', reason: \Throwable}>
     */
    public function allSettled(array $futures): array;

    /**
     * Waits until at least one future completed, without taking any result, and splits the futures by key into the
     * completed and the still pending ones.
     *
     * @param array<array-key, DurableFuture> $futures
     * @return array{completed: array<array-key, DurableFuture>, pending: array<array-key, DurableFuture>}
     */
    public function waitCompleted(array $futures): array;

    /**
     * Runs a side effect once and records its result in the journal; replay returns the recorded result.
     *
     * @param Serde|class-string<Serde>|null $serde
     */
    public function run(string $name, callable $callback, RunRetryPolicy|RunOptions|null $retryPolicy = null, Serde|string|null $serde = null): mixed;
}
