<?php

declare(strict_types=1);

namespace Restate\JsonSchema\Generation;

interface ProvidesExamples
{
    /** @return iterable<mixed> JSON-compatible values */
    public static function jsonSchemaExamples(): iterable;
}
