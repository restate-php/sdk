<?php

declare(strict_types=1);

namespace Restate;

use Restate\Internal\SerdeResolver;

final readonly class RunOptions
{
    private function __construct(
        public readonly ?RunRetryPolicy $retryPolicy = null,
        public readonly ?Serde $serde = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function retryPolicy(RunRetryPolicy $retryPolicy): self
    {
        return new self($retryPolicy);
    }

    /** @param Serde|class-string<Serde> $serde */
    public static function serde(Serde|string $serde): self
    {
        return new self(serde: SerdeResolver::resolve($serde));
    }

    public function withRetryPolicy(RunRetryPolicy $retryPolicy): self
    {
        return new self($retryPolicy, $this->serde);
    }

    /** @param Serde|class-string<Serde> $serde */
    public function withSerde(Serde|string $serde): self
    {
        return new self($this->retryPolicy, SerdeResolver::resolve($serde));
    }
}
