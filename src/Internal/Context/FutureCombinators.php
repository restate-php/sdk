<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use InvalidArgumentException;
use Restate\DurableFuture;
use Restate\DurableFutureAggregateException;
use Restate\Internal\AwaitCombinator;
use Restate\Internal\InvocationOperations;
use Restate\Internal\ResultDecoder;
use Restate\Internal\SuspensionException;

/**
 * The future combinators of one context ({@see \Restate\Context::all()} and its siblings), over the futures that
 * context created.
 *
 * Each call validates its futures and splits off those already resolved in one pass, and makes one backend call per
 * tree it awaits: the tree's result lists the handles then completed ({@see InvocationOperations::doAwaitTree()}), in
 * key order since the tree is built in key order. Taking a result costs its future's own calls, once per future and
 * attempt. A select() loop over n futures is so O(n) backend calls per attempt; what remains quadratic is the O(r)
 * work per call over the r futures it is given (this pass, the extension converting the tree) and shared-core reducing
 * the tree once per result it processes.
 *
 * @internal
 */
final readonly class FutureCombinators
{
    public function __construct(private InvocationOperations $operations)
    {
    }

    /**
     * @param array<array-key, mixed> $futures
     * @return array<array-key, mixed>
     */
    public function all(array $futures): array
    {
        $operation = 'Context::all()';
        [$resolved, $pending, $keyOf] = $this->partitioned($futures, $operation);
        $results = [];

        foreach ($resolved as $key => $future) {
            $results[$key] = $future->awaitResult();
        }

        while ($keyOf !== []) {
            // The VM resolves the tree once every future succeeded or on the first failure, and on protocol v7
            // Restate resumes a suspended invocation only then.
            foreach ($this->awaitTree(AwaitCombinator::AllSucceededOrFirstFailed, $keyOf, $operation) as $handle => $key) {
                unset($keyOf[$handle]);
                $results[$key] = $pending[$key]->awaitResult();
            }
        }

        return self::orderedResults($futures, $results);
    }

    /** @param array<array-key, mixed> $futures */
    public function race(array $futures): mixed
    {
        $operation = 'Context::race()';
        [$resolved, $pending, $keyOf] = $this->partitioned($futures, $operation, nonEmpty: true);
        foreach ($resolved as $future) {
            return $future->awaitResult();
        }

        return $pending[$this->awaitAnyKey($keyOf, $operation)]->awaitResult();
    }

    /**
     * The aggregate lists the failures in the order the futures completed. That order is why this awaits one
     * completion at a time instead of awaiting the core's first-succeeded-or-all-failed tree: after that tree resolves
     * the VM can tell which futures completed, but not in which order.
     *
     * @param array<array-key, mixed> $futures
     */
    public function any(array $futures): mixed
    {
        $operation = 'Context::any()';
        [$resolved, $pending, $keyOf] = $this->partitioned($futures, $operation);
        $failures = [];

        while (true) {
            foreach ($resolved as $key => $future) {
                try {
                    return $future->awaitResult();
                } catch (SuspensionException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $failures[$key] = $e;
                }
            }
            if ($keyOf === []) {
                break;
            }

            $key = $this->awaitAnyKey($keyOf, $operation);
            $future = $pending[$key];
            unset($pending[$key], $keyOf[$future->handle]);
            try {
                return $future->awaitResult();
            } catch (SuspensionException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $failures[$key] = $e;
            }

            // Only code run by that awaitResult() (a deserializer awaiting another of these futures) can have resolved
            // a pending future; the next round takes those first, in key order, as a fresh scan would.
            $resolved = [];
            foreach ($pending as $pendingKey => $pendingFuture) {
                if ($pendingFuture->resolved) {
                    $resolved[$pendingKey] = $pendingFuture;
                    unset($pending[$pendingKey], $keyOf[$pendingFuture->handle]);
                }
            }
        }

        throw new DurableFutureAggregateException($failures);
    }

    /**
     * @param array<array-key, mixed> $futures
     * @return array<array-key, array{status: 'fulfilled', value: mixed}|array{status: 'rejected', reason: \Throwable}>
     */
    public function allSettled(array $futures): array
    {
        $operation = 'Context::allSettled()';
        [$resolved, $pending, $keyOf] = $this->partitioned($futures, $operation);
        $results = [];

        foreach ($resolved as $key => $future) {
            $results[$key] = self::settle($future);
        }

        while ($keyOf !== []) {
            // The VM resolves the tree once every future completed; on protocol v7 Restate resumes a suspended
            // invocation only then.
            foreach ($this->awaitTree(AwaitCombinator::AllCompleted, $keyOf, $operation) as $handle => $key) {
                unset($keyOf[$handle]);
                $results[$key] = self::settle($pending[$key]);
            }
        }

        return self::orderedResults($futures, $results);
    }

    /**
     * @param array<array-key, mixed> $futures
     * @return array{completed: array<array-key, DurableFuture>, pending: array<array-key, DurableFuture>}
     */
    public function waitCompleted(array $futures): array
    {
        $operation = 'Context::waitCompleted()';
        [$completed, $pending, $keyOf] = $this->partitioned($futures, $operation);
        if ($completed !== [] || $keyOf === []) {
            return ['completed' => $completed, 'pending' => $pending];
        }

        $key = $this->awaitAnyKey($keyOf, $operation);
        $completed[$key] = $pending[$key];
        unset($pending[$key]);

        return ['completed' => $completed, 'pending' => $pending];
    }

    /** @param array<array-key, mixed> $futures */
    public function select(array $futures): int|string
    {
        $operation = 'Context::select()';
        [$resolved, , $keyOf] = $this->partitioned($futures, $operation, nonEmpty: true);

        return array_key_first($resolved) ?? $this->awaitAnyKey($keyOf, $operation);
    }

    /**
     * Validates the futures and splits them in one pass: those already resolved, and the others with the key of each
     * one's handle. Both keep the key order.
     *
     * @param array<array-key, mixed> $futures
     *
     * @return array{0: array<array-key, DurableFutureImpl>, 1: array<array-key, DurableFutureImpl>, 2: array<int, array-key>}
     *     resolved futures, pending futures, and handle => key of the pending ones
     */
    private function partitioned(array $futures, string $operation, bool $nonEmpty = false): array
    {
        $resolved = [];
        $pending = [];
        $keyOf = [];
        $resolvedHandles = [];
        foreach ($futures as $key => $future) {
            if (!$future instanceof DurableFutureImpl || $future->operations !== $this->operations) {
                throw new InvalidArgumentException($future instanceof DurableFuture
                    ? "{$operation} futures must belong to this context."
                    : "{$operation} accepts only DurableFuture instances.");
            }

            $handle = $future->handle;
            if (isset($keyOf[$handle]) || isset($resolvedHandles[$handle])) {
                throw new InvalidArgumentException("{$operation} futures must have unique handles.");
            }

            if ($future->resolved) {
                $resolvedHandles[$handle] = true;
                $resolved[$key] = $future;
            } else {
                $keyOf[$handle] = $key;
                $pending[$key] = $future;
            }
        }
        if ($nonEmpty && $futures === []) {
            throw new InvalidArgumentException("{$operation} requires at least one durable future.");
        }

        return [$resolved, $pending, $keyOf];
    }

    /**
     * The key of the first future, in key order, the VM reports completed once one of them completed.
     *
     * @param array<int, array-key> $keyOf handle => key of the futures awaited, none resolved yet
     */
    private function awaitAnyKey(array $keyOf, string $operation): int|string
    {
        $completed = $this->awaitTree(AwaitCombinator::FirstCompleted, $keyOf, $operation);

        return $completed[array_key_first($completed)];
    }

    /**
     * Awaits the combinator over the futures (none of them resolved yet) and returns those the VM then reports
     * completed, handle => key, in key order, without taking their results.
     *
     * @param array<int, array-key> $keyOf handle => key of the futures awaited, in key order
     *
     * @return non-empty-array<int, array-key>
     */
    private function awaitTree(AwaitCombinator $combinator, array $keyOf, string $operation): array
    {
        if ($keyOf === []) {
            throw new \LogicException("{$operation} awaits a tree without futures.");
        }

        $result = $this->operations->doAwaitTree($combinator->tree(array_keys($keyOf)));
        ResultDecoder::assertCompleted($result, $operation, "{$operation} suspended.");

        $completed = [];
        foreach ($result->completedHandles as $handle) {
            $completed[$handle] = $keyOf[$handle] ?? throw new \RuntimeException("{$operation} await reported handle {$handle} completed, which it did not await.");
        }
        if ($completed === []) {
            throw new \RuntimeException("{$operation} await completed, but none of its durable futures did.");
        }

        return $completed;
    }

    /** @return array{status: 'fulfilled', value: mixed}|array{status: 'rejected', reason: \Throwable} */
    private static function settle(DurableFuture $future): array
    {
        try {
            return ['status' => 'fulfilled', 'value' => $future->awaitResult()];
        } catch (SuspensionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ['status' => 'rejected', 'reason' => $e];
        }
    }

    /**
     * @template T
     *
     * @param array<array-key, mixed> $source
     * @param array<array-key, T> $results
     * @return array<array-key, T>
     */
    private static function orderedResults(array $source, array $results): array
    {
        $ordered = [];
        foreach ($source as $key => $_) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }
}
