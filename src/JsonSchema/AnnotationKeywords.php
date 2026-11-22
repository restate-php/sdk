<?php

declare(strict_types=1);

namespace Restate\JsonSchema;

/** @internal */
trait AnnotationKeywords
{
    public function title(string $title): static
    {
        return $this->withKeyword('title', $title);
    }

    public function description(string $description): static
    {
        return $this->withKeyword('description', $description);
    }

    abstract protected function withKeyword(string $keyword, mixed $value): static;
}
