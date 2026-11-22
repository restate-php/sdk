<?php

declare(strict_types=1);

namespace Restate\Bridge\Symfony;

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
use Symfony\Component\Serializer\Exception\ExceptionInterface as SymfonySerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class SymfonyJsonPayloadAdapter implements JsonValueMapper, JsonSchemaProvider
{
    /**
     * @param array<string, mixed> $normalizationContext
     * @param array<string, mixed> $denormalizationContext
     * @param (callable(JsonValueMappingContext): array<string, mixed>)|null $contextResolver
     */
    public function __construct(
        private readonly NormalizerInterface&DenormalizerInterface $serializer,
        private readonly array $normalizationContext = [],
        private readonly array $denormalizationContext = [],
        private readonly mixed $contextResolver = null,
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
            throw JsonMappingException::at($context->path, 'unsupported Symfony JSON payload mapping type');
        }

        try {
            return $this->serializer->denormalize($value, $className, 'json', $this->contextFor($context, $this->denormalizationContext));
        } catch (SymfonySerializerException $e) {
            throw JsonMappingException::at($context->path, $e->getMessage());
        }
    }

    #[\Override]
    public function normalize(mixed $value, JsonValueMappingContext $context): mixed
    {
        if ($value === null && $context->type?->allowsNull() === true) {
            return null;
        }

        if (self::classNameFor($context->type) === null) {
            throw JsonMappingException::at($context->path, 'unsupported Symfony JSON payload mapping type');
        }

        try {
            return $this->serializer->normalize($value, 'json', $this->contextFor($context, $this->normalizationContext));
        } catch (SymfonySerializerException $e) {
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

    /**
     * @param array<string, mixed> $baseContext
     * @return array<string, mixed>
     */
    private function contextFor(JsonValueMappingContext $context, array $baseContext): array
    {
        if (!is_callable($this->contextResolver)) {
            return $baseContext;
        }

        return array_merge($baseContext, ($this->contextResolver)($context));
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
