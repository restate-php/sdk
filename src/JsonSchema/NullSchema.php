<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use Restate\JsonSchema\Internal\KeywordOrder;

final class NullSchema implements Schema
{
    use KeywordedSchema;

    private const string TYPE = 'null';

    public static function new(): self
    {
        return new self([]);
    }

    public function const(mixed $value = null): static
    {
        return $this->withKeyword('const', JsonValue::from($value));
    }

    public function default(mixed $value = null): static
    {
        return $this->withKeyword('default', JsonValue::from($value));
    }

    /** @return list<string> */
    private static function keywordOrder(): array
    {
        return KeywordOrder::draft202012(KeywordOrder::valueAnnotations());
    }
}
