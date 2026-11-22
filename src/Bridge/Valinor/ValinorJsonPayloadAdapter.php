<?php

declare(strict_types=1);

namespace Restate\Bridge\Valinor;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\Normalizer\Normalizer;
use Exception;
use ReflectionNamedType;
use ReflectionType;
use Restate\JsonMappingException;
use Restate\JsonSchemaContext;
use Restate\JsonSchemaMetadata;
use Restate\JsonSchemaProvider;
use Restate\JsonValueMapper;
use Restate\JsonValueMappingContext;
use Restate\UnsupportedJsonSchemaException;
use stdClass;

final readonly class ValinorJsonPayloadAdapter implements JsonValueMapper, JsonSchemaProvider
{
    /** @param Normalizer<mixed> $normalizer */
    public function __construct(
        private readonly TreeMapper $mapper,
        private readonly Normalizer $normalizer,
    ) {
    }

    #[\Override]
    public function supports(JsonValueMappingContext $context): bool
    {
        return self::classNameFor($context->type) !== null;
    }

    #[\Override]
    public function hydrate(mixed $value, JsonValueMappingContext $context): mixed
    {
        if ($value === null && $context->type?->allowsNull() === true) {
            return null;
        }

        $className = self::classNameFor($context->type);
        if ($className === null) {
            throw JsonMappingException::at($context->path, 'unsupported Valinor JSON payload mapping type');
        }

        try {
            return $this->mapper->map($className, $value);
        } catch (MappingError $e) {
            throw JsonMappingException::at($context->path, $e->getMessage());
        }
    }

    #[\Override]
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed
    {
        if ($value === null && $context->type?->allowsNull() === true) {
            return null;
        }

        $className = self::classNameFor($context->type);
        if ($className === null) {
            throw JsonMappingException::at($context->path, 'unsupported Valinor JSON payload mapping type');
        }
        if (!$value instanceof $className) {
            throw JsonMappingException::at($context->path, 'expected ' . $className . ', got ' . gettype($value));
        }

        try {
            return $this->normalizer->normalize($value);
        } catch (Exception $e) {
            throw JsonMappingException::at($context->path, $e->getMessage());
        }
    }

    #[\Override]
    public function schemaFor(JsonSchemaContext $context): JsonSchemaMetadata
    {
        if (self::classNameFor($context->type) === null) {
            throw UnsupportedJsonSchemaException::forContext($context);
        }

        return JsonSchemaMetadata::unknown();
    }

    private static function classNameFor(?ReflectionType $type): ?string
    {
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();
        if (strcasecmp($name, stdClass::class) === 0 || enum_exists($name) || !class_exists($name)) {
            return null;
        }

        return $name;
    }
}
