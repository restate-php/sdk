<?php

declare(strict_types=1);

namespace Restate;

use Restate\Internal\ServiceDefinitionConstruction;
use Restate\Reflection\HandlerPayloadInferer;
use Restate\Reflection\ServiceReflector;

final readonly class HandlerDefinition
{
    public const string TYPE_EXCLUSIVE = 'EXCLUSIVE';
    public const string TYPE_SHARED = 'SHARED';
    public const string TYPE_WORKFLOW = 'WORKFLOW';

    public readonly \Closure $callback;

    /**
     * The PHP signature the handler's context type and payloads are read from: the callback's own, or the method a
     * callback forwards to (a handler of a service class instantiated per invocation, {@see ServiceReflector::fromClass()}).
     */
    public readonly \ReflectionFunctionAbstract $signature;
    public readonly Payload $inputPayload;
    public readonly Payload $outputPayload;
    public readonly ?\ReflectionType $inputType;
    public readonly ?\ReflectionType $outputType;
    public readonly bool $inputPayloadExplicit;
    public readonly bool $outputPayloadExplicit;
    /** @var array<string, string> */
    public readonly array $metadata;

    /**
     * @param array<array-key, mixed> $metadata
     * @param ?\ReflectionFunctionAbstract $signature what $callback forwards to, when it does not declare the handler's
     *                                                parameters and return type itself; defaults to $callback's own
     */
    public function __construct(
        public readonly string $name,
        callable $callback,
        public readonly ?string $type = null,
        ?Payload $inputPayload = null,
        ?Payload $outputPayload = null,
        public readonly ?string $documentation = null,
        array $metadata = [],
        public readonly ?InvocationRetryPolicy $invocationRetryPolicy = null,
        public readonly ?InvocationConfiguration $invocationConfiguration = null,
        ?\ReflectionFunctionAbstract $signature = null,
    ) {
        ServiceDefinitionConstruction::assertValidHandlerName($name);
        ServiceDefinitionConstruction::assertValidHandlerType($type);
        $validatedMetadata = ServiceDefinitionConstruction::validatedMetadata($metadata, 'Handler');

        $this->callback = \Closure::fromCallable($callback);
        $this->signature = $signature ?? new \ReflectionFunction($this->callback);
        $this->metadata = $validatedMetadata;
        $this->inputPayloadExplicit = $inputPayload !== null;
        $this->outputPayloadExplicit = $outputPayload !== null;
        [$this->inputPayload, $this->outputPayload, $this->inputType, $this->outputType] = HandlerPayloadInferer::payloadsFor($name, $this->signature, $inputPayload, $outputPayload);
    }
}
