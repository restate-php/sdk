<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Internal;

/** @internal */
final class KeywordOrder
{
    /** @var list<string> */
    private const array DRAFT_2020_12_REFERENCE = [
        '$id',
        '$vocabulary',
        '$anchor',
        '$dynamicAnchor',
        '$ref',
        '$dynamicRef',
        '$defs',
        '$comment',
        'title',
        'description',
        'deprecated',
        'readOnly',
        'writeOnly',
        'allOf',
        'anyOf',
        'oneOf',
        'not',
        'if',
        'then',
        'else',
        'const',
        'enum',
        'default',
        'examples',
    ];

    /** @var list<string> */
    private const array DRAFT_2020_12_COMMON = [
        '$id',
        '$vocabulary',
        '$anchor',
        '$dynamicAnchor',
        '$ref',
        '$dynamicRef',
        '$defs',
        '$comment',
        'title',
        'description',
        'deprecated',
        'readOnly',
        'writeOnly',
        'type',
        'allOf',
        'anyOf',
        'oneOf',
        'not',
        'if',
        'then',
        'else',
    ];

    /** @var list<string> */
    private const array VALUE_ANNOTATIONS = ['const', 'enum', 'default', 'examples'];

    /** @var list<string> */
    private const array NUMERIC_VALIDATION = ['multipleOf', 'minimum', 'exclusiveMinimum', 'maximum', 'exclusiveMaximum'];

    private function __construct()
    {
    }

    /**
     * @param list<string> $suffix
     * @return list<string>
     */
    public static function draft202012(array $suffix = []): array
    {
        return [...self::DRAFT_2020_12_COMMON, ...$suffix];
    }

    /** @return list<string> */
    public static function draft202012Reference(): array
    {
        return self::DRAFT_2020_12_REFERENCE;
    }

    /** @return list<string> */
    public static function valueAnnotations(): array
    {
        return self::VALUE_ANNOTATIONS;
    }

    /** @return list<string> */
    public static function numericValidationAndAnnotations(): array
    {
        return [...self::NUMERIC_VALIDATION, ...self::VALUE_ANNOTATIONS];
    }
}
