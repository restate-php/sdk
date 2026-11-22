<?php

declare(strict_types=1);

namespace Restate\Internal;

use LogicException;
use ReflectionType;
use Restate\HandlerDefinition;
use Restate\JournalValueCodec;
use Restate\JsonMappingException;
use Restate\JsonPayloadOptions;
use Restate\JsonSchemaContext;
use Restate\JsonSchemaMetadata;
use Restate\JsonSerde;
use Restate\JsonValueMappingContext;
use Restate\Payload;
use Restate\Reflection\BuiltinJsonValueMapper;
use Restate\Serde;
use Restate\ServiceDefinition;
use Restate\TerminalError;
use Restate\UnsupportedJsonSchemaException;

/**
 * A handler's input or output payload as the endpoint applies it: serde, JSON value mapping and schema metadata.
 *
 * @internal
 */
final readonly class EffectiveHandlerPayload
{
    private function __construct(
        private string $serviceName,
        private string $handlerName,
        private Payload $payload,
        private ?ReflectionType $type,
        private string $role,
        private JsonPayloadOptions $jsonPayloads,
    ) {
    }

    public static function input(ServiceDefinition $service, HandlerDefinition $handler, Serde $endpointDefaultSerde, JsonPayloadOptions $jsonPayloads): self
    {
        return new self(
            $service->name,
            $handler->name,
            self::resolve($handler->inputPayload, $handler->inputPayloadExplicit, self::defaultSerdeFor($service, $endpointDefaultSerde)),
            $handler->inputType,
            JsonValueMappingContext::ROLE_INPUT,
            $jsonPayloads,
        );
    }

    public static function output(ServiceDefinition $service, HandlerDefinition $handler, Serde $endpointDefaultSerde, JsonPayloadOptions $jsonPayloads): self
    {
        return new self(
            $service->name,
            $handler->name,
            self::resolve($handler->outputPayload, $handler->outputPayloadExplicit, self::defaultSerdeFor($service, $endpointDefaultSerde)),
            $handler->outputType,
            JsonValueMappingContext::ROLE_OUTPUT,
            $jsonPayloads,
        );
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $schema = $this->resolvedJsonSchema();

        return $this->role === JsonValueMappingContext::ROLE_INPUT
            ? $this->payload->inputManifest($schema)
            : $this->payload->outputManifest($schema);
    }

    public function decodeInput(string $input, JournalValueCodec $journalValueCodec): mixed
    {
        if ($this->role !== JsonValueMappingContext::ROLE_INPUT) {
            throw new LogicException('Output payloads cannot decode handler input.');
        }

        if ($this->payload->isEmpty()) {
            return null;
        }

        if ($this->payload->isBinary()) {
            return $input;
        }

        $serde = $this->payload->serde();
        if (!$this->payload->isJson()) {
            if ($serde === null) {
                throw new TerminalError('Input payload serde is not available.', 500);
            }

            try {
                return $serde->deserialize($journalValueCodec->decode($input));
            } catch (\JsonException $e) {
                throw self::invalidJsonInput($e);
            }
        }

        try {
            $decodedInput = $journalValueCodec->decode($input);
            $value = $serde === null ? json_decode($decodedInput, true, flags: JSON_THROW_ON_ERROR) : $serde->deserialize($decodedInput);
            $builtInValue = $serde instanceof JsonSerde ? json_decode($decodedInput, false, flags: JSON_THROW_ON_ERROR) : $value;
        } catch (\JsonException $e) {
            throw self::invalidJsonInput($e);
        }
        $context = $this->jsonValueMappingContext();

        try {
            return $this->mapJsonInput($value, $builtInValue, $context);
        } catch (JsonMappingException $e) {
            throw new TerminalError($e->getMessage(), 400);
        }
    }

    public function encodeOutput(mixed $result, JournalValueCodec $journalValueCodec): string
    {
        if ($this->role !== JsonValueMappingContext::ROLE_OUTPUT) {
            throw new LogicException('Input payloads cannot encode handler output.');
        }

        if ($this->payload->isEmpty()) {
            return '';
        }

        if ($this->payload->isBinary()) {
            if (!is_string($result)) {
                throw new TerminalError('Binary output handlers must return a string.', 500);
            }

            return $result;
        }

        $serde = $this->payload->serde();
        if (!$this->payload->isJson()) {
            if ($serde === null) {
                throw new TerminalError('Output payload serde is not available.', 500);
            }

            return $journalValueCodec->encode($serde->serialize($result));
        }

        try {
            $result = $this->mapJsonOutput($result, $this->jsonValueMappingContext());
        } catch (JsonMappingException $e) {
            throw new TerminalError($e->getMessage(), 500);
        }

        $bytes = $serde === null ? json_encode($result, JSON_THROW_ON_ERROR) : $serde->serialize($result);

        return $journalValueCodec->encode($bytes);
    }

    /**
     * The only place a \JsonException becomes a terminal failure: the handler
     * input cannot be decoded, so retrying the invocation cannot succeed.
     */
    private static function invalidJsonInput(\JsonException $e): TerminalError
    {
        return new TerminalError('Invalid JSON payload: ' . $e->getMessage(), 400);
    }

    private static function resolve(Payload $payload, bool $explicit, Serde $defaultSerde): Payload
    {
        if ($explicit || $payload->isEmpty() || $defaultSerde instanceof JsonSerde) {
            return $payload;
        }

        return Payload::custom($defaultSerde);
    }

    private static function defaultSerdeFor(ServiceDefinition $service, Serde $endpointDefaultSerde): Serde
    {
        return $service->defaultSerde ?? $endpointDefaultSerde;
    }

    private function resolvedJsonSchema(): ?JsonSchemaMetadata
    {
        if (!$this->payload->isJson()) {
            return null;
        }

        $explicitSchema = $this->payload->explicitJsonSchemaMetadata();
        if ($explicitSchema !== null) {
            return $explicitSchema;
        }

        $schemaProvider = $this->jsonPayloads->schemaProvider();
        if ($schemaProvider === null) {
            return JsonSchemaMetadata::unknown();
        }

        try {
            return $schemaProvider->schemaFor(new JsonSchemaContext($this->type, $this->role, $this->serviceName, $this->handlerName));
        } catch (UnsupportedJsonSchemaException) {
            return JsonSchemaMetadata::unknown();
        }
    }

    private function jsonValueMappingContext(): JsonValueMappingContext
    {
        return new JsonValueMappingContext($this->type, $this->role, $this->serviceName, $this->handlerName);
    }

    private function mapJsonInput(mixed $value, mixed $builtInValue, JsonValueMappingContext $context): mixed
    {
        $customMapper = $this->jsonPayloads->valueMapper();
        if ($customMapper !== null && $customMapper->supports($context)) {
            return $customMapper->hydrate($value, $context);
        }

        $builtInMapper = new BuiltinJsonValueMapper();
        if ($builtInMapper->supports($context)) {
            return $builtInMapper->hydrate($builtInValue, $context);
        }

        if (BuiltinJsonValueMapper::requiresMapping($context->type)) {
            throw new TerminalError("DTO mapping is not available for {$context->serviceName}/{$context->handlerName} input.", 500);
        }

        return $value;
    }

    private function mapJsonOutput(mixed $value, JsonValueMappingContext $context): mixed
    {
        $customMapper = $this->jsonPayloads->valueMapper();
        if ($customMapper !== null && $customMapper->supports($context)) {
            return $customMapper->normalize($value, $context);
        }

        $builtInMapper = new BuiltinJsonValueMapper();
        if ($builtInMapper->supports($context)) {
            return $builtInMapper->normalize($value, $context);
        }

        if (BuiltinJsonValueMapper::requiresMapping($context->type)) {
            throw new TerminalError("DTO mapping is not available for {$context->serviceName}/{$context->handlerName} output.", 500);
        }

        return $value;
    }
}
