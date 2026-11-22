<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;

final readonly class FallbackJsonSchemaProvider implements JsonSchemaProvider
{
    /** @var non-empty-list<JsonSchemaProvider> */
    private readonly array $providers;

    public function __construct(JsonSchemaProvider ...$providers)
    {
        if ($providers === []) {
            throw new InvalidArgumentException('Fallback JSON Schema provider requires at least one provider.');
        }

        $this->providers = array_values($providers);
    }

    #[\Override]
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        foreach ($this->providers as $provider) {
            try {
                return $provider->schemaFor($context);
            } catch (UnsupportedJsonSchemaException) {
            }
        }

        throw UnsupportedJsonSchemaException::forContext($context);
    }
}
