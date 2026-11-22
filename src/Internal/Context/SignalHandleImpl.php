<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use Restate\Internal\InvocationOperations;
use Restate\Internal\SerdeResolver;
use Restate\JournalValueCodec;
use Restate\Serde;
use Restate\SignalHandle;
use Restate\TerminalError;

/**
 * A {@see SignalHandle}: the signal $name of the invocation whose id $invocationId returns when the signal is sent.
 *
 * @internal
 */
final readonly class SignalHandleImpl implements SignalHandle
{
    /** @param \Closure(): string $invocationId */
    public function __construct(
        private InvocationOperations $operations,
        private \Closure $invocationId,
        private string $name,
        private Serde $serde,
        private ?JournalValueCodec $journalValueCodec = null,
    ) {
        self::assertValidName($name);
    }

    public static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Signal name must not be empty.');
        }
    }

    /** @param Serde|class-string<Serde>|null $serde */
    #[\Override]
    public function resolve(mixed $value = null, Serde|string|null $serde = null): void
    {
        $serde = $serde === null ? $this->serde : SerdeResolver::resolve($serde);
        $bytes = $serde->serialize($value);
        if ($this->journalValueCodec !== null) {
            $bytes = $this->journalValueCodec->encode($bytes);
        }

        $this->operations->sysResolveSignal(($this->invocationId)(), $this->name, $bytes);
    }

    #[\Override]
    public function reject(TerminalError $failure): void
    {
        $this->operations->sysRejectSignal(($this->invocationId)(), $this->name, $failure->statusCode(), $failure->getMessage(), $failure->metadata());
    }
}
