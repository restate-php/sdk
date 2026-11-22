<?php

declare(strict_types=1);

namespace Restate;

interface JournalValueCodec
{
    public function encode(string $bytes): string;

    public function decode(string $bytes): string;
}
