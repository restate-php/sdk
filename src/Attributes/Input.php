<?php

declare(strict_types=1);

namespace Restate\Attributes;

use InvalidArgumentException;
use Restate\Payload;
use Restate\Serde;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Input
{
    /**
     * @param array<string, mixed>|bool|null $schema
     * @param class-string<Serde>|null $serde
     */
    public function __construct(
        public readonly array|bool|null $schema = null,
        public readonly bool $required = false,
        public readonly string $contentType = 'application/json',
        public readonly ?string $accept = null,
        public readonly bool $json = true,
        public readonly bool $empty = false,
        public readonly ?string $serde = null,
    ) {
    }

    public function payload(): Payload
    {
        $this->validate();

        if ($this->empty) {
            return Payload::empty();
        }

        if ($this->serde !== null) {
            $payload = Payload::custom($this->serde, $this->schema, required: $this->required);

            return $this->accept === null ? $payload : $payload->withAcceptContentType($this->accept);
        }

        if ($this->json) {
            $payload = Payload::json($this->schema, required: $this->required);

            return $this->accept === null ? $payload : $payload->withAcceptContentType($this->accept);
        }

        $payload = Payload::binary($this->contentType);

        return $this->accept === null ? $payload : $payload->withAcceptContentType($this->accept);
    }

    private function validate(): void
    {
        if ($this->contentType === '') {
            throw new InvalidArgumentException('Input payload content type must not be empty.');
        }
        if ($this->accept === '') {
            throw new InvalidArgumentException('Input payload accept content type must not be empty.');
        }
        if ($this->serde !== null && $this->empty) {
            throw new InvalidArgumentException('Input custom serde payload cannot be empty.');
        }
        if ($this->serde !== null && !$this->json) {
            throw new InvalidArgumentException('Input custom serde payload cannot be binary.');
        }
        if ($this->serde !== null && $this->contentType !== 'application/json') {
            throw new InvalidArgumentException('Input custom serde payload cannot define a custom content type.');
        }
        if ($this->empty && $this->required) {
            throw new InvalidArgumentException('Input empty payload cannot be required.');
        }
        if ($this->empty && $this->schema !== null) {
            throw new InvalidArgumentException('Input payload cannot be empty and define a JSON schema.');
        }
        if ($this->empty && $this->contentType !== 'application/json') {
            throw new InvalidArgumentException('Input payload cannot be empty and define a custom content type.');
        }
        if ($this->empty && $this->accept !== null) {
            throw new InvalidArgumentException('Input payload cannot be empty and define an accept content type.');
        }
        if ($this->json && $this->contentType !== 'application/json') {
            throw new InvalidArgumentException('Input JSON payload content type must be application/json.');
        }
        if (!$this->json && $this->required) {
            throw new InvalidArgumentException('Input binary payload cannot be required.');
        }
        if (!$this->json && $this->schema !== null) {
            throw new InvalidArgumentException('Input binary payload cannot define a JSON schema.');
        }
    }
}
