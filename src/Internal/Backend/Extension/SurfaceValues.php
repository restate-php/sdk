<?php

declare(strict_types=1);

namespace Restate\Internal\Backend\Extension;

use Restate\AttachTarget;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\AwaitTree;
use Restate\Internal\Backend\AwakeableHandle;
use Restate\Internal\Backend\CallTarget;
use Restate\Internal\Backend\CoreLogRecord;
use Restate\Internal\Backend\InvocationInput;
use Restate\Internal\Backend\NotificationKind;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Backend\ResponseHead;
use Restate\Internal\Backend\RunCompletionStatus;
use Restate\Internal\BackendException;
use Restate\Internal\TerminalMetadata;
use Restate\RunRetryPolicy;

/**
 * The conversions between the typed Backend Contract and the PHP values of the Extension Surface
 * (`Restate\Extension\Vm`, shapes in extension/restate.stub.php), in one place. Arguments become the arrays the extension
 * takes; the arrays it returns become contract values, and a shape the contract does not know is a BackendException
 * (category internal), never a silently wrong value.
 *
 * @internal
 *
 * @phpstan-import-type CallTarget from \Restate\Extension\Vm as ExtensionCallTarget
 * @phpstan-import-type AttachTarget from \Restate\Extension\Vm as ExtensionAttachTarget
 * @phpstan-import-type RetryPolicy from \Restate\Extension\Vm as ExtensionRetryPolicy
 * @phpstan-import-type AwaitTree from \Restate\Extension\Vm as ExtensionAwaitTree
 */
final class SurfaceValues
{
    /** @return ExtensionCallTarget */
    public static function callTarget(CallTarget $target): array
    {
        $scoped = $target->target->toArray();
        $array = [
            'kind' => $scoped['kind'],
            'service' => $scoped['service'],
            'handler' => $scoped['handler'],
            'key' => $scoped['key'],
        ];
        if ($scoped['scope'] !== null) {
            $array['scope'] = $scoped['scope'];
        }
        if ($target->limitKey !== null) {
            $array['limit_key'] = $target->limitKey;
        }
        if ($target->headers !== []) {
            $array['headers'] = $target->headers;
        }

        return $array;
    }

    /** @return ExtensionAttachTarget */
    public static function attachTarget(AttachTarget $target): array
    {
        return $target->toArray();
    }

    /** @return ExtensionRetryPolicy|null null means infinite */
    public static function retryPolicy(?RunRetryPolicy $policy): ?array
    {
        return $policy?->toArray();
    }

    /** @return ExtensionAwaitTree */
    public static function awaitTree(AwaitTree $tree): array
    {
        // A loop, not array_map(): a tree over n handles is converted once per combinator call, and most are flat.
        $children = [];
        foreach ($tree->children as $child) {
            $children[] = is_int($child) ? $child : self::awaitTree($child);
        }

        return [$tree->combinator->value, $children];
    }

    /** @param array<array-key, mixed> $result */
    public static function awaitResult(array $result): AwaitResult
    {
        $state = AwaitState::tryFrom(self::type($result)) ?? throw self::unexpected('await result', $result);
        if ($state !== AwaitState::ExecuteRun) {
            return AwaitResult::of($state);
        }

        $handle = $result['handle'] ?? null;

        return is_int($handle) ? AwaitResult::executeRun($handle) : throw self::unexpected('await result', $result);
    }

    /**
     * doAwaitTree()'s result: as {@see SurfaceValues::awaitResult()}, but `any_completed` lists the tree's handles
     * that are then completed (`completed`).
     *
     * @param array<array-key, mixed> $result
     */
    public static function awaitTreeResult(array $result): AwaitResult
    {
        if (self::type($result) !== AwaitState::Completed->value) {
            return self::awaitResult($result);
        }

        $handles = $result['completed'] ?? null;

        return is_array($handles) && array_is_list($handles) && array_all($handles, static fn (mixed $handle): bool => is_int($handle))
            ? AwaitResult::completed($handles)
            : throw self::unexpected('await tree result', $result);
    }

    /** @param array<array-key, mixed> $result */
    public static function notificationResult(array $result): NotificationResult
    {
        $kind = NotificationKind::tryFrom(self::type($result)) ?? throw self::unexpected('notification result', $result);

        return match ($kind) {
            NotificationKind::Success => is_string($value = $result['value'] ?? null)
                ? NotificationResult::success($value)
                : throw self::unexpected('notification result', $result),
            NotificationKind::Void => NotificationResult::void(),
            NotificationKind::Failure => is_int($code = $result['code'] ?? null) && is_string($message = $result['message'] ?? null)
                ? NotificationResult::failure($code, $message, TerminalMetadata::fromDecoded($result['metadata'] ?? []))
                : throw self::unexpected('notification result', $result),
            NotificationKind::InvocationId => is_string($id = $result['invocation_id'] ?? null)
                ? NotificationResult::invocationId($id)
                : throw self::unexpected('notification result', $result),
            NotificationKind::StateKeys => self::isStringList($keys = $result['keys'] ?? null)
                ? NotificationResult::stateKeys($keys)
                : throw self::unexpected('notification result', $result),
        };
    }

    /** @param array<array-key, mixed> $result */
    public static function runCompletionStatus(array $result): RunCompletionStatus
    {
        return RunCompletionStatus::tryFrom(self::type($result)) ?? throw self::unexpected('run completion status', $result);
    }

    /** @param array<array-key, mixed> $result */
    public static function awakeable(array $result): AwakeableHandle
    {
        $id = $result['id'] ?? null;
        $handle = $result['handle'] ?? null;

        return is_string($id) && is_int($handle) ? new AwakeableHandle($id, $handle) : throw self::unexpected('awakeable', $result);
    }

    /**
     * What `Vm::takeLogs()` returns.
     *
     * @param array<array-key, mixed> $result
     *
     * @return list<CoreLogRecord>
     */
    public static function coreLogRecords(array $result): array
    {
        return array_is_list($result)
            ? array_map(static fn (mixed $record): CoreLogRecord => is_array($record) ? self::coreLogRecord($record) : throw self::unexpected('core log record', $result), $result)
            : throw self::unexpected('core log records', $result);
    }

    /** @param array<array-key, mixed> $result */
    public static function coreLogRecord(array $result): CoreLogRecord
    {
        $level = $result['level'] ?? null;
        $target = $result['target'] ?? null;
        $message = $result['message'] ?? null;
        $fields = $result['fields'] ?? null;
        if (!in_array($level, ['error', 'warn', 'info', 'debug', 'trace'], true) || !is_string($target) || !is_string($message) || !is_array($fields)) {
            throw self::unexpected('core log record', $result);
        }

        $named = [];
        foreach ($fields as $name => $value) {
            $named[(string) $name] = is_string($value) ? $value : throw self::unexpected('core log record', $result);
        }

        return new CoreLogRecord($level, $target, $message, $named);
    }

    /** @param array<array-key, mixed> $result */
    public static function responseHead(array $result): ResponseHead
    {
        $status = $result['status_code'] ?? null;
        $headers = $result['headers'] ?? null;

        return is_int($status) && self::isHeaders($headers) ? new ResponseHead($status, $headers) : throw self::unexpected('response head', $result);
    }

    /** @param array<array-key, mixed> $result */
    public static function invocationInput(array $result): InvocationInput
    {
        $id = $result['invocation_id'] ?? null;
        $seed = $result['random_seed'] ?? null;
        $key = $result['key'] ?? null;
        $headers = $result['headers'] ?? null;
        $input = $result['input'] ?? null;
        $scope = $result['scope'] ?? null;
        $limitKey = $result['limit_key'] ?? null;
        $idempotencyKey = $result['idempotency_key'] ?? null;
        if (!is_string($id) || !is_int($seed) || !is_string($key) || !self::isHeaders($headers) || !is_string($input)
            || !self::isOptionalString($scope) || !self::isOptionalString($limitKey) || !self::isOptionalString($idempotencyKey)) {
            throw self::unexpected('invocation input', $result);
        }

        return new InvocationInput($id, $seed, $key, $headers, $input, $scope, $limitKey, $idempotencyKey);
    }

    /** @param array<array-key, mixed> $result */
    private static function type(array $result): string
    {
        $type = $result['type'] ?? null;

        return is_string($type) ? $type : throw self::unexpected('result', $result);
    }

    /** @phpstan-assert-if-true list<string> $value */
    private static function isStringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => is_string($item));
    }

    /** @phpstan-assert-if-true list<array{0: string, 1: string}> $value */
    private static function isHeaders(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_all(
            $value,
            static fn (mixed $pair): bool => is_array($pair) && array_keys($pair) === [0, 1] && is_string($pair[0]) && is_string($pair[1]),
        );
    }

    /** @phpstan-assert-if-true ?string $value */
    private static function isOptionalString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    /** @param array<array-key, mixed> $result */
    private static function unexpected(string $what, array $result): BackendException
    {
        $shape = json_encode(array_map(static fn (mixed $value): string => get_debug_type($value), $result), JSON_UNESCAPED_SLASHES);
        if ($shape === false) {
            $shape = '(unprintable)';
        }
        $type = is_string($result['type'] ?? null) ? " of type '{$result['type']}'" : '';

        return new BackendException("The extension returned an unexpected {$what}{$type}: {$shape}.");
    }
}
