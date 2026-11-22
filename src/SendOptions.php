<?php

declare(strict_types=1);

namespace Restate;

use Restate\Internal\FlowControlKeys;
use Restate\Internal\SerdeResolver;
use Restate\Internal\RequestHeaders;

final readonly class SendOptions
{
    private function __construct(
        public readonly ?int $delayMillis = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?Serde $inputSerde = null,
        public readonly ?Serde $outputSerde = null,
        /** @var list<array{0: string, 1: string}> */
        public readonly array $headers = [],
        /** The concurrency limit key within the target's scope ({@see ClientRequest::limitKey()}). */
        public readonly ?string $limitKey = null,
    ) {
        if ($limitKey !== null) {
            FlowControlKeys::assertLimitKey($limitKey);
        }
    }

    public static function none(): self
    {
        return new self();
    }

    public static function delayMillis(int $delayMillis): self
    {
        return new self($delayMillis);
    }

    /** The concurrency limit key within the target's scope; see {@see ClientRequest::limitKey()}. */
    public static function limitKey(string $limitKey): self
    {
        return new self(limitKey: $limitKey);
    }

    /** @param Serde|class-string<Serde> $serde */
    public static function serde(Serde|string $serde): self
    {
        $serde = SerdeResolver::resolve($serde);

        return new self(inputSerde: $serde, outputSerde: $serde);
    }

    /** @param Serde|class-string<Serde> $serde */
    public static function inputSerde(Serde|string $serde): self
    {
        return new self(inputSerde: SerdeResolver::resolve($serde));
    }

    /** @param Serde|class-string<Serde> $serde */
    public static function outputSerde(Serde|string $serde): self
    {
        return new self(outputSerde: SerdeResolver::resolve($serde));
    }

    public static function header(string $name, string $value): self
    {
        return new self(headers: RequestHeaders::normalize([[$name, $value]]));
    }

    /** @param array<string, string>|list<array{0: string, 1: string}> $headers */
    public static function headers(array $headers): self
    {
        return new self(headers: RequestHeaders::normalize($headers));
    }

    public function withIdempotencyKey(string $idempotencyKey): self
    {
        return new self($this->delayMillis, $idempotencyKey, $this->inputSerde, $this->outputSerde, $this->headers, $this->limitKey);
    }

    public function withDelayMillis(int $delayMillis): self
    {
        return new self($delayMillis, $this->idempotencyKey, $this->inputSerde, $this->outputSerde, $this->headers, $this->limitKey);
    }

    public function withLimitKey(string $limitKey): self
    {
        return new self($this->delayMillis, $this->idempotencyKey, $this->inputSerde, $this->outputSerde, $this->headers, $limitKey);
    }

    /** @param Serde|class-string<Serde> $serde */
    public function withSerde(Serde|string $serde): self
    {
        $serde = SerdeResolver::resolve($serde);

        return new self($this->delayMillis, $this->idempotencyKey, $serde, $serde, $this->headers, $this->limitKey);
    }

    /** @param Serde|class-string<Serde> $serde */
    public function withInputSerde(Serde|string $serde): self
    {
        return new self($this->delayMillis, $this->idempotencyKey, SerdeResolver::resolve($serde), $this->outputSerde, $this->headers, $this->limitKey);
    }

    /** @param Serde|class-string<Serde> $serde */
    public function withOutputSerde(Serde|string $serde): self
    {
        return new self($this->delayMillis, $this->idempotencyKey, $this->inputSerde, SerdeResolver::resolve($serde), $this->headers, $this->limitKey);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self(
            $this->delayMillis,
            $this->idempotencyKey,
            $this->inputSerde,
            $this->outputSerde,
            [...$this->headers, ...RequestHeaders::normalize([[$name, $value]])],
            $this->limitKey,
        );
    }

    /** @param array<string, string>|list<array{0: string, 1: string}> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->delayMillis, $this->idempotencyKey, $this->inputSerde, $this->outputSerde, RequestHeaders::normalize($headers), $this->limitKey);
    }
}
