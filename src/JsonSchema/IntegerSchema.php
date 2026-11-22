<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;
use Restate\JsonSchema\Internal\KeywordOrder;

final class IntegerSchema implements Schema
{
    use KeywordedSchema;

    private const string TYPE = 'integer';

    public static function new(): self
    {
        return new self([]);
    }

    public function multipleOf(int $multipleOf): self
    {
        if ($multipleOf <= 0) {
            throw new InvalidArgumentException('JSON Schema multipleOf must be greater than zero.');
        }

        return $this->withKeyword('multipleOf', $multipleOf);
    }

    public function minimum(int $minimum): self
    {
        return $this->withKeyword('minimum', $minimum);
    }

    public function exclusiveMinimum(int $exclusiveMinimum): self
    {
        return $this->withKeyword('exclusiveMinimum', $exclusiveMinimum);
    }

    public function maximum(int $maximum): self
    {
        return $this->withKeyword('maximum', $maximum);
    }

    public function exclusiveMaximum(int $exclusiveMaximum): self
    {
        return $this->withKeyword('exclusiveMaximum', $exclusiveMaximum);
    }

    /** @return list<string> */
    private static function keywordOrder(): array
    {
        return KeywordOrder::draft202012(KeywordOrder::numericValidationAndAnnotations());
    }
}
