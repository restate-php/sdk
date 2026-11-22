<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Internal;

/** @internal */
final class TypeKeyword
{
    private function __construct()
    {
    }

    /**
     * @param string|list<string> $type
     * @return string|non-empty-list<string>
     */
    public static function nullable(string|array $type): string|array
    {
        if ($type === 'null') {
            return $type;
        }

        $types = is_array($type) ? $type : [$type];
        if (!in_array('null', $types, true)) {
            $types[] = 'null';
        }

        /** @var non-empty-list<string> $types */
        return $types;
    }
}
