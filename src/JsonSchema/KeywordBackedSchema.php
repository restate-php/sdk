<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;
use Restate\JsonSchema\Internal\TypeKeyword;

/**
 * Keyword storage and the keywords every schema object accepts.
 *
 * @internal
 */
trait KeywordBackedSchema
{
    /** @var array<string, mixed> */
    private readonly array $keywords;

    /** @param array<string, mixed> $keywords */
    private function __construct(array $keywords)
    {
        $this->keywords = $keywords;
    }

    /** @return array<string, mixed> */
    protected function keywords(): array
    {
        return $this->keywords;
    }

    protected function hasKeyword(string $keyword): bool
    {
        return array_key_exists($keyword, $this->keywords);
    }

    protected function keyword(string $keyword): mixed
    {
        return $this->keywords[$keyword];
    }

    protected function withKeyword(string $keyword, mixed $value): static
    {
        return $this->withKeywords([...$this->keywords, $keyword => $value]);
    }

    /** @param string|list<string> $type */
    protected function withNullableType(string|array $type): static
    {
        if ($this->hasKeyword('type')) {
            $type = $this->keyword('type');
        }

        return $this->withKeyword('type', TypeKeyword::nullable($type));
    }

    public function const(mixed $value): static
    {
        return $this->withKeyword('const', JsonValue::from($value));
    }

    /** @param array<array-key, mixed> $values */
    public function enum(array $values): static
    {
        return $this->withKeyword('enum', self::jsonValues($values, 'enum'));
    }

    public function default(mixed $value): static
    {
        return $this->withKeyword('default', JsonValue::from($value));
    }

    /** @param array<array-key, mixed> $values */
    public function examples(array $values): static
    {
        return $this->withKeyword('examples', self::jsonValues($values, 'examples', false));
    }

    public function deprecated(bool $deprecated = true): static
    {
        return $this->withKeyword('deprecated', $deprecated);
    }

    public function readOnly(bool $readOnly = true): static
    {
        return $this->withKeyword('readOnly', $readOnly);
    }

    public function writeOnly(bool $writeOnly = true): static
    {
        return $this->withKeyword('writeOnly', $writeOnly);
    }

    public function comment(string $comment): static
    {
        return $this->withKeyword('$comment', $comment);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<JsonValue>
     */
    private static function jsonValues(array $values, string $keyword, bool $nonEmpty = true): array
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException("JSON Schema {$keyword} must be a list of JSON values.");
        }

        if ($nonEmpty && $values === []) {
            throw new InvalidArgumentException("JSON Schema {$keyword} must contain at least one JSON value.");
        }

        return array_map(JsonValue::from(...), $values);
    }

    /** @param array<string, mixed> $keywords */
    abstract protected function withKeywords(array $keywords): static;
}
