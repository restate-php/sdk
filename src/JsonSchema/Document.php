<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use JsonSerializable;
use LogicException;
use Restate\JsonSchema\Internal\KeywordSerializer;
use stdClass;

final class Document implements JsonSerializable
{
    /** The `$schema` a document declares: the builders produce Draft 2020-12 only. */
    public const string DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    private function __construct(
        private readonly Schema $root,
    ) {
    }

    public static function new(Schema $root): self
    {
        return new self($root);
    }

    public function rootSchema(): Schema
    {
        return $this->root;
    }

    /** @return array<string, mixed>|stdClass|bool */
    public function toArray(): array|stdClass|bool
    {
        return $this->documentRoot($this->root->toArray());
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed>|stdClass|bool */
    #[\Override]
    public function jsonSerialize(): array|stdClass|bool
    {
        return $this->documentRoot($this->root->jsonSerialize());
    }

    /** @return array<string, mixed>|stdClass|bool */
    private function documentRoot(mixed $root): array|stdClass|bool
    {
        if (is_bool($root)) {
            return $root;
        }

        if (!is_array($root) && !$root instanceof stdClass) {
            throw new LogicException('JSON Schema documents can only wrap object or boolean schema values.');
        }

        return KeywordSerializer::documentRoot($root, self::DIALECT);
    }
}
