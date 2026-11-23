<?php

declare(strict_types=1);

namespace Restate;

use Restate\Internal\FlowControlKeys;
use Restate\Internal\SerdeResolver;
use Restate\Internal\RequestHeaders;

/**
 * One request to a handler from a handler, configured fluently and then called or sent through the context. Each
 * configuration method returns a new request.
 *
 * TOutput is the handler's result as the output serde decodes it: `mixed` for a request made with a handler name, the
 * handler's return type for a request a typed client declares. Another output serde makes it `mixed` again (also one
 * given in the CallOptions or SendOptions of a call or send, which the type does not follow).
 *
 * @template-covariant TOutput = mixed
 */
final readonly class ClientRequest
{
    /**
     * @param Serde|class-string<Serde>|null $inputSerde
     * @param Serde|class-string<Serde>|null $outputSerde
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     *
     * @internal built by the clients a handler context hands out ({@see Context::serviceClient()}, …)
     */
    public function __construct(
        private readonly Context $context,
        private readonly Target $target,
        private readonly mixed $payload = null,
        private readonly ?string $idempotencyKey = null,
        private readonly Serde|string|null $inputSerde = null,
        private readonly Serde|string|null $outputSerde = null,
        private readonly array $headers = [],
        private readonly ?string $limitKey = null,
    ) {
        RequestHeaders::normalize($this->headers);
        if ($limitKey !== null) {
            FlowControlKeys::assertLimitKey($limitKey);
        }
    }

    /** @return self<TOutput> */
    public function idempotencyKey(string $key): self
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Idempotency key must not be empty.');
        }

        return new self($this->context, $this->target, $this->payload, $key, $this->inputSerde, $this->outputSerde, $this->headers, $this->limitKey);
    }

    /**
     * The concurrency limit key within the target's scope: one or two levels separated by `/` (for example `user-42` or
     * `eu/user-42`), each 1 to 36 characters from `[a-zA-Z0-9_.-]`. Restate's concurrency rules match it below the
     * scope; it is not part of the target's identity. Needs a scoped target ({@see Context::scope()},
     * {@see Target::scoped()}) and service protocol v7 (Restate 1.8 or newer).
     *
     * @return self<TOutput>
     */
    public function limitKey(string $limitKey): self
    {
        return new self($this->context, $this->target, $this->payload, $this->idempotencyKey, $this->inputSerde, $this->outputSerde, $this->headers, $limitKey);
    }

    /**
     * @param Serde|class-string<Serde> $serde
     *
     * @return self<mixed>
     */
    public function serde(Serde|string $serde): self
    {
        $serde = SerdeResolver::resolve($serde);

        return new self($this->context, $this->target, $this->payload, $this->idempotencyKey, $serde, $serde, $this->headers, $this->limitKey);
    }

    /**
     * @param Serde|class-string<Serde> $serde
     *
     * @return self<TOutput>
     */
    public function inputSerde(Serde|string $serde): self
    {
        return new self($this->context, $this->target, $this->payload, $this->idempotencyKey, SerdeResolver::resolve($serde), $this->outputSerde, $this->headers, $this->limitKey);
    }

    /**
     * @param Serde|class-string<Serde> $serde
     *
     * @return self<mixed>
     */
    public function outputSerde(Serde|string $serde): self
    {
        return new self($this->context, $this->target, $this->payload, $this->idempotencyKey, $this->inputSerde, SerdeResolver::resolve($serde), $this->headers, $this->limitKey);
    }

    /** @return self<TOutput> */
    public function header(string $name, string $value): self
    {
        return new self(
            $this->context,
            $this->target,
            $this->payload,
            $this->idempotencyKey,
            $this->inputSerde,
            $this->outputSerde,
            [...RequestHeaders::normalize($this->headers), ...RequestHeaders::normalize([[$name, $value]])],
            $this->limitKey,
        );
    }

    /**
     * @param array<string, string>|list<array{0: string, 1: string}> $headers
     *
     * @return self<TOutput>
     */
    public function headers(array $headers): self
    {
        return new self(
            $this->context,
            $this->target,
            $this->payload,
            $this->idempotencyKey,
            $this->inputSerde,
            $this->outputSerde,
            RequestHeaders::normalize($headers),
            $this->limitKey,
        );
    }

    /** @return TOutput */
    public function call(?CallOptions $options = null): mixed
    {
        return $this->context->call(
            $this->target,
            $this->payload,
            $options->idempotencyKey ?? $this->idempotencyKey,
            $options->inputSerde ?? $this->inputSerde,
            $options->outputSerde ?? $this->outputSerde,
            self::headersFor($this->headers, $options?->headers),
            $options->limitKey ?? $this->limitKey,
        );
    }

    /** @return DurableFuture<TOutput> */
    public function callFuture(?CallOptions $options = null): DurableFuture
    {
        /** @var DurableFuture<TOutput> */
        return $this->context->callFuture(
            $this->target,
            $this->payload,
            $options->idempotencyKey ?? $this->idempotencyKey,
            $options->inputSerde ?? $this->inputSerde,
            $options->outputSerde ?? $this->outputSerde,
            self::headersFor($this->headers, $options?->headers),
            $options->limitKey ?? $this->limitKey,
        );
    }

    /** @return InvocationHandle<TOutput> */
    public function send(?SendOptions $options = null): InvocationHandle
    {
        $idempotencyKey = $options->idempotencyKey ?? $this->idempotencyKey;
        $inputSerde = $options->inputSerde ?? $this->inputSerde;
        $outputSerde = $options->outputSerde ?? $this->outputSerde;
        $headers = self::headersFor($this->headers, $options?->headers);
        $limitKey = $options->limitKey ?? $this->limitKey;

        if ($options === null || $options->delayMillis === null) {
            /** @var InvocationHandle<TOutput> */
            return $this->context->send($this->target, $this->payload, $idempotencyKey, $inputSerde, $outputSerde, $headers, $limitKey);
        }

        /** @var InvocationHandle<TOutput> */
        return $this->context->sendAfter($this->target, $this->payload, $options->delayMillis, $idempotencyKey, $inputSerde, $outputSerde, $headers, $limitKey);
    }

    /** @return InvocationHandle<TOutput> */
    public function sendAfter(int $delayMillis, ?SendOptions $options = null): InvocationHandle
    {
        /** @var InvocationHandle<TOutput> */
        return $this->context->sendAfter(
            $this->target,
            $this->payload,
            $delayMillis,
            $options->idempotencyKey ?? $this->idempotencyKey,
            $options->inputSerde ?? $this->inputSerde,
            $options->outputSerde ?? $this->outputSerde,
            self::headersFor($this->headers, $options?->headers),
            $options->limitKey ?? $this->limitKey,
        );
    }

    /**
     * @param array<string, string>|list<array{0: string, 1: string}> $requestHeaders
     * @param array<string, string>|list<array{0: string, 1: string}>|null $optionHeaders
     * @return list<array{0: string, 1: string}>
     */
    private static function headersFor(array $requestHeaders, ?array $optionHeaders): array
    {
        return [
            ...RequestHeaders::normalize($requestHeaders),
            ...RequestHeaders::normalize($optionHeaders ?? []),
        ];
    }
}
