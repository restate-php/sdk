<?php

declare(strict_types=1);

namespace Restate;

final class NoopJournalValueCodec implements JournalValueCodec
{
    #[\Override]
    public function encode(string $bytes): string
    {
        return $bytes;
    }

    #[\Override]
    public function decode(string $bytes): string
    {
        return $bytes;
    }
}
