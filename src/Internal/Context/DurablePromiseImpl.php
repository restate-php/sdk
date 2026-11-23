<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use Restate\DurableFuture;
use Restate\DurablePromise;
use Restate\Internal\InvocationOperations;
use Restate\Internal\ResultDecoder;
use Restate\Internal\SerdeResolver;
use Restate\JournalValueCodec;
use Restate\NoopJournalValueCodec;
use Restate\Serde;
use Restate\TerminalError;

/**
 * A {@see DurablePromise} of the workflow an invocation's operations belong to.
 *
 * @internal
 */
final readonly class DurablePromiseImpl implements DurablePromise
{
    private Serde $serde;

    /** @param Serde|class-string<Serde>|null $serde */
    public function __construct(private InvocationOperations $operations, private string $name, Serde|string|null $serde = null, private ?JournalValueCodec $journalValueCodec = null)
    {
        $this->serde = SerdeResolver::resolveOrJson($serde);
    }

    /** @param Serde|class-string<Serde>|null $serde */
    #[\Override]
    public function value(Serde|string|null $serde = null): mixed
    {
        return ResultDecoder::value('promise get', $this->serde($serde), $this->codec())->await($this->operations, $this->operations->sysGetPromise($this->name), 'Promise get suspended.');
    }

    /** @param Serde|class-string<Serde>|null $serde */
    #[\Override]
    public function peek(Serde|string|null $serde = null): mixed
    {
        return ResultDecoder::value('promise peek', $this->serde($serde), $this->codec())->await($this->operations, $this->operations->sysPeekPromise($this->name), 'Promise peek suspended.');
    }

    /** @param Serde|class-string<Serde>|null $serde */
    #[\Override]
    public function future(Serde|string|null $serde = null): DurableFuture
    {
        $serde = $this->serde($serde);
        $handle = $this->operations->sysGetPromise($this->name);

        return DurableFutureImpl::of($this->operations, $handle, ResultDecoder::value('promise get', $serde, $this->codec()), 'Promise get suspended.');
    }

    /** @param Serde|class-string<Serde>|null $serde */
    #[\Override]
    public function resolve(mixed $value, Serde|string|null $serde = null): void
    {
        $handle = $this->operations->sysResolvePromise($this->name, $this->codec()->encode($this->serde($serde)->serialize($value)));
        ResultDecoder::void('promise resolution')->await($this->operations, $handle, 'Promise resolution suspended.');
    }

    #[\Override]
    public function reject(TerminalError $failure): void
    {
        $handle = $this->operations->sysRejectPromise($this->name, $failure->statusCode(), $failure->getMessage(), $failure->metadata());
        ResultDecoder::void('promise rejection')->await($this->operations, $handle, 'Promise rejection suspended.');
    }

    /** @param Serde|class-string<Serde>|null $serde */
    private function serde(Serde|string|null $serde): Serde
    {
        return $serde === null ? $this->serde : SerdeResolver::resolve($serde);
    }

    private function codec(): JournalValueCodec
    {
        return $this->journalValueCodec ?? new NoopJournalValueCodec();
    }
}
