<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;
use Restate\JsonSchema\Internal\KeywordOrder;

final class NumberSchema implements Schema
{
    use KeywordedSchema;

    private const string TYPE = 'number';

    public static function new(): self
    {
        return new self([]);
    }

    public function multipleOf(int|float $multipleOf): self
    {
        $multipleOf = self::finiteNumber($multipleOf, 'multipleOf');

        if ($multipleOf <= 0) {
            throw new InvalidArgumentException('JSON Schema multipleOf must be greater than zero.');
        }

        return $this->withKeyword('multipleOf', $multipleOf);
    }

    public function minimum(int|float $minimum): self
    {
        return $this->withKeyword('minimum', self::finiteNumber($minimum, 'minimum'));
    }

    public function exclusiveMinimum(int|float $exclusiveMinimum): self
    {
        return $this->withKeyword('exclusiveMinimum', self::finiteNumber($exclusiveMinimum, 'exclusiveMinimum'));
    }

    public function maximum(int|float $maximum): self
    {
        return $this->withKeyword('maximum', self::finiteNumber($maximum, 'maximum'));
    }

    public function exclusiveMaximum(int|float $exclusiveMaximum): self
    {
        return $this->withKeyword('exclusiveMaximum', self::finiteNumber($exclusiveMaximum, 'exclusiveMaximum'));
    }

    private static function finiteNumber(int|float $value, string $keyword): int|float
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException("JSON Schema {$keyword} must be finite.");
        }

        return $value;
    }

    /** @return list<string> */
    private static function keywordOrder(): array
    {
        return KeywordOrder::draft202012(KeywordOrder::numericValidationAndAnnotations());
    }
}
