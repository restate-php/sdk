<?php

declare(strict_types=1);

namespace Restate;

use InvalidArgumentException;
use Restate\Internal\FlowControlKeys;

/**
 * The invocation to attach to or read the output of: by invocation id, by workflow, or by the idempotency key of a call.
 *
 * @phpstan-import-type AttachTarget from \Restate\Extension\Vm as ExtensionAttachTarget
 */
final readonly class AttachTarget
{
    /** @param ExtensionAttachTarget $payload */
    private function __construct(private readonly array $payload)
    {
    }

    public static function invocationId(string $invocationId): self
    {
        self::assertNotEmpty($invocationId, 'Invocation ID must not be empty.');

        return new self(['kind' => 'invocation', 'invocation_id' => $invocationId]);
    }

    /**
     * The run of the workflow $name with $key, within $scope if it was started in one (a workflow key names a different
     * run in each scope; see {@see Target::scoped()}). A scope needs service protocol v7 (Restate 1.8 or newer).
     */
    public static function workflow(string $name, string $key, ?string $scope = null): self
    {
        self::assertServiceName($name);
        self::assertNotEmpty($key, 'Workflow key must not be empty.');
        if ($scope !== null) {
            FlowControlKeys::assertScope($scope);
        }

        return new self(['kind' => 'workflow', 'name' => $name, 'key' => $key, 'scope' => $scope]);
    }

    /**
     * The invocation of $target that was called or sent with $idempotencyKey; in the target's scope, if it has one
     * (idempotency keys are per scope).
     */
    public static function idempotency(Target $target, string $idempotencyKey): self
    {
        self::assertNotEmpty($idempotencyKey, 'Idempotency key must not be empty.');
        $targetPayload = $target->toArray();

        return new self([
            'kind' => 'idempotency',
            'service_name' => $targetPayload['service'],
            'service_key' => $targetPayload['key'],
            'handler_name' => $targetPayload['handler'],
            'idempotency_key' => $idempotencyKey,
            'scope' => $targetPayload['scope'],
        ]);
    }

    /**
     * @internal The target as the Extension Surface takes it (`kind` plus the fields of that kind); only the extension
     *           backend converts it.
     *
     * @return ExtensionAttachTarget
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    private static function assertServiceName(string $name): void
    {
        if (preg_match('/^([a-zA-Z]|_[a-zA-Z0-9])[a-zA-Z0-9._-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid Restate service name {$name}.");
        }
    }

    private static function assertNotEmpty(string $value, string $message): void
    {
        if ($value === '') {
            throw new InvalidArgumentException($message);
        }
    }
}
