<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;
use stdClass;

final class RawSchema implements Schema
{
    private function __construct(
        private readonly JsonValue $content,
    ) {
    }

    public static function new(mixed $content): self
    {
        if ($content instanceof Schema) {
            throw new InvalidArgumentException('Raw schemas cannot wrap schema objects; compose schema objects with the builders.');
        }

        if (!is_array($content) && !$content instanceof stdClass) {
            throw new InvalidArgumentException('Raw schemas must be JSON object values.');
        }

        if (is_array($content) && $content !== [] && array_is_list($content)) {
            throw new InvalidArgumentException('Raw schemas must be JSON object values.');
        }

        self::assertCompatibleDialect($content);

        return new self(JsonValue::object($content));
    }

    public function property(string $name): PropertySchema
    {
        return PropertySchema::new($name, $this);
    }

    #[\Override]
    public function toArray(): mixed
    {
        return $this->content->toArray();
    }

    #[\Override]
    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->content->jsonSerialize();
    }

    /** @param array<array-key, mixed>|stdClass $content */
    private static function assertCompatibleDialect(array|stdClass $content): void
    {
        $properties = $content instanceof stdClass ? get_object_vars($content) : $content;

        if (!array_key_exists('$schema', $properties)) {
            return;
        }

        if ($properties['$schema'] !== Document::DIALECT) {
            throw new InvalidArgumentException('Raw schemas must not declare a $schema other than Draft 2020-12.');
        }
    }
}
