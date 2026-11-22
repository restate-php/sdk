<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use Restate\AttachTarget;
use Restate\Internal\InvocationOperations;
use Restate\Internal\ResultDecoder;
use Restate\Internal\SerdeResolver;
use Restate\InvocationHandle;
use Restate\JournalValueCodec;
use Restate\Serde;
use Restate\SignalHandle;

/**
 * An {@see InvocationHandle}: by attach target, by known invocation id, or by the pending invocation-id handle of a
 * send ({@see pending()}).
 *
 * @internal
 */
final class InvocationHandleImpl implements InvocationHandle
{
    private readonly Serde $serde;

    private readonly Serde $signalSerde;

    /**
     * @param Serde|class-string<Serde>|null $serde the output's serde
     * @param ?Serde $signalSerde what a signal is resolved with by default: the context's default serde
     */
    public function __construct(
        private readonly InvocationOperations $operations,
        private ?AttachTarget $target,
        private ?string $knownInvocationId = null,
        private ?int $invocationIdHandle = null,
        Serde|string|null $serde = null,
        private readonly ?JournalValueCodec $journalValueCodec = null,
        ?Serde $signalSerde = null,
    ) {
        $this->serde = SerdeResolver::resolveOrJson($serde);
        $this->signalSerde = SerdeResolver::resolveOrJson($signalSerde);
    }

    /** @param Serde|class-string<Serde>|null $serde */
    public static function pending(InvocationOperations $operations, int $invocationIdHandle, Serde|string|null $serde = null, ?JournalValueCodec $journalValueCodec = null, ?Serde $signalSerde = null): self
    {
        return new self($operations, null, null, $invocationIdHandle, $serde, $journalValueCodec, $signalSerde);
    }

    #[\Override]
    public function id(): string
    {
        if ($this->knownInvocationId !== null) {
            return $this->knownInvocationId;
        }

        if ($this->invocationIdHandle !== null) {
            $id = $this->resolveInvocationId();
            $this->knownInvocationId = $id;
            $this->target = AttachTarget::invocationId($id);
            $this->invocationIdHandle = null;

            return $id;
        }

        throw new \RuntimeException('Invocation ID is not available for this handle.');
    }

    /** @param Serde|class-string<Serde>|null $serde */
    #[\Override]
    public function output(Serde|string|null $serde = null): mixed
    {
        $serde = $serde === null ? $this->serde : SerdeResolver::resolve($serde);
        $handle = $this->operations->sysAttachInvocation($this->outputTarget());

        return ResultDecoder::serde('invocation output', $serde, $this->journalValueCodec)->await($this->operations, $handle, 'Invocation output suspended.');
    }

    /** @param Serde|class-string<Serde>|null $serde */
    #[\Override]
    public function peekOutput(Serde|string|null $serde = null): mixed
    {
        $serde = $serde === null ? $this->serde : SerdeResolver::resolve($serde);
        $handle = $this->operations->sysGetInvocationOutput($this->outputTarget());

        return ResultDecoder::serde('invocation output', $serde, $this->journalValueCodec)->await($this->operations, $handle, 'Invocation output suspended.');
    }

    private function outputTarget(): AttachTarget
    {
        if ($this->invocationIdHandle !== null) {
            $this->id();
        }

        if ($this->target === null) {
            throw new \RuntimeException('Invocation output requires an attach target.');
        }

        return $this->target;
    }

    #[\Override]
    public function cancel(): void
    {
        if ($this->invocationIdHandle !== null) {
            $this->id();
        }

        if ($this->knownInvocationId === null) {
            throw new \RuntimeException('Invocation cancellation requires a known invocation ID.');
        }

        $this->operations->sysCancelInvocation($this->knownInvocationId);
    }

    #[\Override]
    public function signal(string $name): SignalHandle
    {
        return new SignalHandleImpl($this->operations, $this->signalTarget(...), $name, $this->signalSerde, $this->journalValueCodec);
    }

    private function signalTarget(): string
    {
        if ($this->invocationIdHandle !== null) {
            $this->id();
        }

        if ($this->knownInvocationId === null) {
            throw new \RuntimeException('Signalling an invocation requires a known invocation ID.');
        }

        return $this->knownInvocationId;
    }

    private function resolveInvocationId(): string
    {
        if ($this->invocationIdHandle === null) {
            throw new \RuntimeException('Invocation ID is not available for this handle.');
        }

        return ResultDecoder::invocationId()->await($this->operations, $this->invocationIdHandle, 'Invocation ID suspended.');
    }
}
