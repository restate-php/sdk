<?php

declare(strict_types=1);

namespace Restate\Internal\Context;

use Restate\Awakeable;
use Restate\Internal\InvocationOperations;
use Restate\Internal\ResultDecoder;
use Restate\Internal\SerdeResolver;
use Restate\JournalValueCodec;
use Restate\Serde;

/**
 * An {@see Awakeable} over the handle of its awakeable operation.
 *
 * @internal
 */
final readonly class AwakeableImpl implements Awakeable
{
    private Serde $serde;

    /** @param Serde|class-string<Serde>|null $serde */
    public function __construct(
        private InvocationOperations $operations,
        private string $id,
        private int $handle,
        Serde|string|null $serde = null,
        private ?JournalValueCodec $journalValueCodec = null,
    ) {
        $this->serde = SerdeResolver::resolveOrJson($serde);
    }

    #[\Override]
    public function id(): string
    {
        return $this->id;
    }

    /** @param Serde|class-string<Serde>|null $serde */
    #[\Override]
    public function value(Serde|string|null $serde = null): mixed
    {
        $serde = $serde === null ? $this->serde : SerdeResolver::resolve($serde);

        return ResultDecoder::value('awakeable', $serde, $this->journalValueCodec)->await($this->operations, $this->handle, 'Awakeable suspended.');
    }
}
