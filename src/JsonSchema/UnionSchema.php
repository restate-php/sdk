<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

use InvalidArgumentException;
use Restate\JsonSchema\Internal\KeywordOrder;

final class UnionSchema implements Schema
{
    use KeywordedSchema;

    private const array TYPE = [];

    /** @param list<Type> $types */
    public static function new(array $types): self
    {
        return new self(['type' => self::validatedTypes($types)]);
    }

    /**
     * @param list<Type> $types
     * @return non-empty-list<string>
     */
    private static function validatedTypes(array $types): array
    {
        if ($types === []) {
            throw new InvalidArgumentException('JSON Schema union type array must contain at least one type.');
        }

        $seen = [];
        $values = [];
        foreach ($types as $type) {
            if (isset($seen[$type->value])) {
                throw new InvalidArgumentException(sprintf('JSON Schema union type "%s" must not be duplicated.', $type->value));
            }

            $seen[$type->value] = true;
            $values[] = $type->value;
        }

        return $values;
    }

    /** @return list<string> */
    private static function keywordOrder(): array
    {
        return KeywordOrder::draft202012(KeywordOrder::valueAnnotations());
    }
}
