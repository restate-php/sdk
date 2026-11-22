<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

enum Type: string
{
    case Null = 'null';
    case Boolean = 'boolean';
    case Object = 'object';
    case Array = 'array';
    case Number = 'number';
    case String = 'string';
    case Integer = 'integer';
}
