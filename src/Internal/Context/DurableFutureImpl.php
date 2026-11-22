<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use Restate\DurableFuture;
use Restate\Internal\InvocationOperations;
use Restate\Internal\ResultDecoder;
use Restate\JournalValueCodec;

/**
 * A {@see DurableFuture} over one handle of an invocation's operations, whose result a {@see ResultDecoder} decodes.
 *
 * @internal
 */
final class DurableFutureImpl implements DurableFuture
{
    /**
     * Whether this future took its result (what isCompleted() reports). Public to read, like $operations and $handle,
     * so the combinators, which check every future they are given on each call, read properties instead of calling
     * methods.
     */
    public private(set) bool $resolved = false;

    private mixed $value = null;

    private ?\Throwable $throwable = null;

    /** @param ResultDecoder<mixed> $decoder */
    private function __construct(
        /** The operations of the invocation whose context created it; combinators accept only their own. */
        public readonly InvocationOperations $operations,
        public readonly int $handle,
        private readonly ResultDecoder $decoder,
        private readonly string $suspensionMessage,
    ) {
    }

    /** @param ResultDecoder<mixed> $decoder */
    public static function of(InvocationOperations $operations, int $handle, ResultDecoder $decoder, string $suspensionMessage): self
    {
        return new self($operations, $handle, $decoder, $suspensionMessage);
    }

    public static function jsonResult(InvocationOperations $operations, int $handle, string $operation, string $suspensionMessage, ?JournalValueCodec $codec = null): self
    {
        return new self($operations, $handle, ResultDecoder::json($operation, $codec), $suspensionMessage);
    }

    public static function voidResult(InvocationOperations $operations, int $handle, string $operation, string $suspensionMessage): self
    {
        return new self($operations, $handle, ResultDecoder::void($operation), $suspensionMessage);
    }

    public static function invocationId(InvocationOperations $operations, int $handle, string $suspensionMessage): self
    {
        return new self($operations, $handle, ResultDecoder::invocationId(), $suspensionMessage);
    }

    #[\Override]
    public function isCompleted(): bool
    {
        return $this->resolved;
    }

    #[\Override]
    public function awaitResult(): mixed
    {
        if ($this->resolved) {
            if ($this->throwable !== null) {
                throw $this->throwable;
            }

            return $this->value;
        }

        $this->decoder->assertAwaited($this->operations->doAwait($this->handle), $this->suspensionMessage);

        try {
            $this->value = $this->decoder->decode($this->operations->takeNotificationResult($this->handle));
            $this->resolved = true;
        } catch (\Throwable $e) {
            $this->throwable = $e;
            $this->resolved = true;

            throw $e;
        }

        return $this->value;
    }
}
