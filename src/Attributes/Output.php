<?php

declare(strict_types=1);

namespace Restate\Attributes;

use InvalidArgumentException;
use Restate\Payload;
use Restate\Serde;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Output
{
    /**
     * @param array<string, mixed>|bool|null $schema
     * @param class-string<Serde>|null $serde
     */
    public function __construct(
        public readonly array|bool|null $schema = null,
        public readonly string $contentType = 'application/json',
        public readonly bool $json = true,
        public readonly bool $empty = false,
        public readonly ?string $serde = null,
        public readonly bool $setContentTypeIfEmpty = false,
    ) {
    }

    public function payload(): Payload
    {
        $this->validate();

        if ($this->empty) {
            return Payload::empty()->withSetContentTypeIfEmpty($this->setContentTypeIfEmpty);
        }

        if ($this->serde !== null) {
            return Payload::custom($this->serde, $this->schema)->withSetContentTypeIfEmpty($this->setContentTypeIfEmpty);
        }

        if ($this->json) {
            return Payload::json($this->schema)->withSetContentTypeIfEmpty($this->setContentTypeIfEmpty);
        }

        return Payload::binary($this->contentType)->withSetContentTypeIfEmpty($this->setContentTypeIfEmpty);
    }

    private function validate(): void
    {
        if ($this->contentType === '') {
            throw new InvalidArgumentException('Output payload content type must not be empty.');
        }
        if ($this->serde !== null && $this->empty) {
            throw new InvalidArgumentException('Output custom serde payload cannot be empty.');
        }
        if ($this->serde !== null && !$this->json) {
            throw new InvalidArgumentException('Output custom serde payload cannot be binary.');
        }
        if ($this->serde !== null && $this->contentType !== 'application/json') {
            throw new InvalidArgumentException('Output custom serde payload cannot define a custom content type.');
        }
        if ($this->empty && $this->schema !== null) {
            throw new InvalidArgumentException('Output payload cannot be empty and define a JSON schema.');
        }
        if ($this->empty && $this->contentType !== 'application/json') {
            throw new InvalidArgumentException('Output payload cannot be empty and define a custom content type.');
        }
        if ($this->json && $this->contentType !== 'application/json') {
            throw new InvalidArgumentException('Output JSON payload content type must be application/json.');
        }
        if (!$this->json && $this->schema !== null) {
            throw new InvalidArgumentException('Output binary payload cannot define a JSON schema.');
        }
    }
}
