<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;

final class DurableFuturesUnordered
{
    /** @var array<int, DurableFuture> */
    private array $futures = [];

    private int $nextIndex = 0;

    public function __construct(private readonly Context $ctx)
    {
    }

    /** @param array<array-key, DurableFuture> $futures */
    public static function from(Context $ctx, array $futures): self
    {
        $unordered = new self($ctx);
        $unordered->pushAll($futures);

        return $unordered;
    }

    public function push(DurableFuture $future): int
    {
        $index = $this->nextIndex++;
        $this->futures[$index] = $future;
        return $index;
    }

    /**
     * @param array<array-key, mixed> $futures
     * @return array<array-key, int>
     */
    public function pushAll(array $futures): array
    {
        $indexes = [];
        foreach ($futures as $key => $future) {
            if (!$future instanceof DurableFuture) {
                throw new InvalidArgumentException('DurableFuturesUnordered accepts only DurableFuture instances.');
            }

            $indexes[$key] = $this->push($future);
        }

        return $indexes;
    }

    public function isEmpty(): bool
    {
        return $this->futures === [];
    }

    public function count(): int
    {
        return count($this->futures);
    }

    /** @return array{index: int, value: mixed}|null */
    public function next(): ?array
    {
        if ($this->futures === []) {
            return null;
        }

        $index = $this->ctx->select($this->futures);
        if (!is_int($index)) {
            throw new \RuntimeException('DurableFuturesUnordered requires integer indexes.');
        }
        $future = $this->futures[$index];
        unset($this->futures[$index]);

        return ['index' => $index, 'value' => $future->awaitResult()];
    }
}
