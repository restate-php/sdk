<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

use Restate\TerminalError;

/**
 * The result of a completed handle ({@see \Restate\Internal\InvocationOperations::takeNotificationResult()}).
 * {@see \Restate\Internal\ResultDecoder} turns it into what the context operation returns.
 *
 * @internal
 */
final readonly class NotificationResult
{
    /**
     * @param array<array-key, string> $metadata
     * @param list<string> $keys
     */
    private function __construct(
        public NotificationKind $kind,
        private string $value = '',
        private int $code = 0,
        private array $metadata = [],
        private array $keys = [],
    ) {
    }

    /** @param string $bytes the payload, as the journal holds it (before the Journal Value Codec) */
    public static function success(string $bytes): self
    {
        return new self(NotificationKind::Success, $bytes);
    }

    public static function void(): self
    {
        return new self(NotificationKind::Void);
    }

    /** @param array<array-key, string> $metadata */
    public static function failure(int $code, string $message, array $metadata = []): self
    {
        return new self(NotificationKind::Failure, $message, $code, $metadata);
    }

    public static function invocationId(string $invocationId): self
    {
        return new self(NotificationKind::InvocationId, $invocationId);
    }

    /** @param list<string> $keys */
    public static function stateKeys(array $keys): self
    {
        return new self(NotificationKind::StateKeys, keys: $keys);
    }

    /** The success payload. */
    public function bytes(): string
    {
        $this->expect(NotificationKind::Success);

        return $this->value;
    }

    /** The failure, as the TerminalError the operation throws. */
    public function terminalError(): TerminalError
    {
        $this->expect(NotificationKind::Failure);

        return new TerminalError($this->value, $this->code, $this->metadata);
    }

    public function invocationIdValue(): string
    {
        $this->expect(NotificationKind::InvocationId);

        return $this->value;
    }

    /** @return list<string> */
    public function keys(): array
    {
        $this->expect(NotificationKind::StateKeys);

        return $this->keys;
    }

    private function expect(NotificationKind $kind): void
    {
        if ($this->kind !== $kind) {
            throw new \LogicException("A {$this->kind->value} notification result has no {$kind->value} value.");
        }
    }
}
