<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;
use Restate\Internal\SerdeResolver;
use Restate\JsonSchema\Schema as JsonSchemaContract;
use stdClass;

final readonly class Payload
{
    private const string KIND_JSON = 'json';
    private const string KIND_BINARY = 'binary';
    private const string KIND_CUSTOM = 'custom';
    private const string KIND_EMPTY = 'empty';

    private function __construct(
        private readonly string $kind,
        private readonly ?JsonSchemaMetadata $schema = null,
        private readonly bool $required = false,
        private readonly ?string $contentType = null,
        private readonly ?Serde $serde = null,
        private readonly ?string $acceptContentType = null,
        private readonly bool $setContentTypeIfEmpty = false,
    ) {
    }

    /** @param JsonSchemaContract|array<string, mixed>|bool|null $schema */
    public static function json(JsonSchemaContract|array|bool|null $schema = null, bool $required = false): self
    {
        return new self(self::KIND_JSON, self::schemaMetadata($schema), $required, 'application/json', JsonSerde::default());
    }

    public static function binary(string $contentType = 'application/octet-stream'): self
    {
        if ($contentType === '') {
            throw new InvalidArgumentException('Payload content type must not be empty.');
        }

        return new self(self::KIND_BINARY, null, false, $contentType);
    }

    /**
     * @param Serde|class-string<Serde> $serde
     * @param JsonSchemaContract|array<string, mixed>|bool|null $schema
     */
    public static function custom(Serde|string $serde, JsonSchemaContract|array|bool|null $schema = null, bool $required = false): self
    {
        $resolved = self::resolveSerde($serde);
        $contentType = $resolved->contentType();
        if ($contentType === '') {
            throw new InvalidArgumentException('Serde content type must not be empty.');
        }

        return new self(self::KIND_CUSTOM, self::schemaMetadata($schema), $required, $contentType, $resolved);
    }

    public static function empty(): self
    {
        return new self(self::KIND_EMPTY);
    }

    public function withAcceptContentType(string $contentType): self
    {
        self::assertContentType($contentType, 'Payload accept content type must not be empty.');

        return new self($this->kind, $this->schema, $this->required, $this->contentType, $this->serde, $contentType, $this->setContentTypeIfEmpty);
    }

    public function withSetContentTypeIfEmpty(bool $setContentTypeIfEmpty = true): self
    {
        return new self($this->kind, $this->schema, $this->required, $this->contentType, $this->serde, $this->acceptContentType, $setContentTypeIfEmpty);
    }

    public function isJson(): bool
    {
        return $this->kind === self::KIND_JSON;
    }

    public function isBinary(): bool
    {
        return $this->kind === self::KIND_BINARY;
    }

    public function isEmpty(): bool
    {
        return $this->kind === self::KIND_EMPTY;
    }

    public function serde(): ?Serde
    {
        return $this->serde;
    }

    /**
     * The content type the payload is written with (for an input, what a caller sends; the endpoint may accept more, see
     * {@see withAcceptContentType()}); null for an empty payload.
     */
    public function contentType(): ?string
    {
        return $this->contentType;
    }

    public function explicitJsonSchemaMetadata(): ?JsonSchemaMetadata
    {
        if ($this->kind !== self::KIND_JSON) {
            return null;
        }

        return $this->schema;
    }

    /** @return array<string, mixed>|stdClass|bool|null */
    public function explicitJsonSchema(): array|stdClass|bool|null
    {
        return $this->explicitJsonSchemaMetadata()?->toArray();
    }

    /** @return array<string, mixed>|stdClass|bool */
    public function jsonSchema(): array|stdClass|bool
    {
        if ($this->kind !== self::KIND_JSON || $this->schema === null) {
            return true;
        }

        $schema = $this->schema->toManifestValue();

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputManifest(?JsonSchemaMetadata $resolvedJsonSchema = null): array
    {
        if ($this->kind === self::KIND_EMPTY) {
            return ['required' => false];
        }

        $manifest = ['required' => $this->required, 'contentType' => $this->acceptContentType ?? $this->contentType];
        if ($this->kind === self::KIND_JSON) {
            $manifest['jsonSchema'] = ($resolvedJsonSchema ?? $this->schema ?? JsonSchemaMetadata::unknown())->toManifestValue();
        }
        if ($this->kind === self::KIND_CUSTOM && $this->contentType === 'application/json' && $this->schema !== null) {
            $manifest['jsonSchema'] = $this->schema->toManifestValue();
        }

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    public function outputManifest(?JsonSchemaMetadata $resolvedJsonSchema = null): array
    {
        if ($this->kind === self::KIND_EMPTY) {
            return ['setContentTypeIfEmpty' => $this->setContentTypeIfEmpty];
        }

        $manifest = ['setContentTypeIfEmpty' => $this->setContentTypeIfEmpty, 'contentType' => $this->contentType];
        if ($this->kind === self::KIND_JSON) {
            $manifest['jsonSchema'] = ($resolvedJsonSchema ?? $this->schema ?? JsonSchemaMetadata::unknown())->toManifestValue();
        }
        if ($this->kind === self::KIND_CUSTOM && $this->contentType === 'application/json' && $this->schema !== null) {
            $manifest['jsonSchema'] = $this->schema->toManifestValue();
        }

        return $manifest;
    }

    /** @param Serde|class-string<Serde> $serde */
    private static function resolveSerde(Serde|string $serde): Serde
    {
        return SerdeResolver::resolve($serde);
    }

    private static function assertContentType(string $contentType, string $message): void
    {
        if ($contentType === '') {
            throw new InvalidArgumentException($message);
        }
    }

    /** @param JsonSchemaContract|array<string, mixed>|bool|null $schema */
    private static function schemaMetadata(JsonSchemaContract|array|bool|null $schema): ?JsonSchemaMetadata
    {
        if ($schema === null) {
            return null;
        }

        if ($schema instanceof JsonSchemaContract) {
            return JsonSchemaMetadata::fromSchema($schema);
        }

        if (is_bool($schema)) {
            return JsonSchemaMetadata::fromSchema($schema ? JsonSchema::alwaysValid() : JsonSchema::neverValid());
        }

        return JsonSchemaMetadata::fromArray($schema);
    }
}
