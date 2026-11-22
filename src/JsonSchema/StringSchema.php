<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;
use Restate\JsonSchema\Internal\KeywordOrder;

final class StringSchema implements Schema
{
    use KeywordedSchema;

    private const string TYPE = 'string';

    public static function new(): self
    {
        return new self([]);
    }

    public function minLength(int $minLength): self
    {
        return $this->withKeyword('minLength', self::nonNegativeInteger($minLength, 'minLength'));
    }

    public function maxLength(int $maxLength): self
    {
        return $this->withKeyword('maxLength', self::nonNegativeInteger($maxLength, 'maxLength'));
    }

    public function pattern(string $pattern): self
    {
        return $this->withKeyword('pattern', $pattern);
    }

    public function format(StringFormat|string $format): self
    {
        return $this->withKeyword('format', $format instanceof StringFormat ? $format->value : $format);
    }

    public function contentEncoding(string $contentEncoding): self
    {
        return $this->withKeyword('contentEncoding', $contentEncoding);
    }

    public function contentMediaType(string $contentMediaType): self
    {
        return $this->withKeyword('contentMediaType', $contentMediaType);
    }

    public function contentSchema(Schema|bool $contentSchema): self
    {
        return $this->withKeyword('contentSchema', $contentSchema);
    }

    private static function nonNegativeInteger(int $value, string $keyword): int
    {
        if ($value < 0) {
            throw new InvalidArgumentException("JSON Schema {$keyword} must be a non-negative integer.");
        }

        return $value;
    }

    /** @return list<string> */
    private static function keywordOrder(): array
    {
        return KeywordOrder::draft202012([
            'minLength',
            'maxLength',
            'pattern',
            'format',
            'contentEncoding',
            'contentMediaType',
            'contentSchema',
            ...KeywordOrder::valueAnnotations(),
        ]);
    }
}
