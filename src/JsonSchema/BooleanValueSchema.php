<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use Restate\JsonSchema\Internal\KeywordOrder;

final class BooleanValueSchema implements Schema
{
    use KeywordedSchema;

    private const string TYPE = 'boolean';

    public static function new(): self
    {
        return new self([]);
    }

    /** @return list<string> */
    private static function keywordOrder(): array
    {
        return KeywordOrder::draft202012(KeywordOrder::valueAnnotations());
    }
}
