<?php

declare(strict_types=1);

namespace Restate\Internal;

use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\NotificationKind;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\Client\MappedJsonSerde;
use Restate\JournalValueCodec;
use Restate\JsonSerde;
use Restate\NoopJournalValueCodec;
use Restate\Serde;
use Restate\TerminalError;

/**
 * The one place that turns what an awaiting context operation gets from its invocation into what the operation
 * returns or throws: the await state (assertCompleted()) and the completed result (decode()).
 *
 * - A suspension throws {@see SuspensionException}, a cancel signal `TerminalError('Invocation cancelled', 409)`, any
 *   other unfinished state a `\RuntimeException` naming the operation.
 * - A failure throws its {@see TerminalError} (code, message, metadata), whatever the operation.
 * - A success payload goes through the Journal Value Codec and then the operation's serializer: json() decodes JSON
 *   and reports malformed JSON as the operation's failure; serde() lets the serializer's own exceptions through.
 * - An empty success payload is null for JSON (json(), and serde() with {@see JsonSerde} or the handler-mapped JSON
 *   serializer of {@see \Restate\Reflection\HandlerSerdes}), without the codec or the serializer: a handler without
 *   output writes no bytes, and no JSON value encodes to none. Any other serializer gets the empty bytes, since they
 *   can be a value of its own format (an empty protobuf message, say). The ingress client decodes an empty response
 *   body the same way.
 * - Void is null for the value and void operations. A result kind the operation never produces is a
 *   `\RuntimeException`.
 *
 * The operation name appears in messages as given mid-sentence and capitalized at the start of one ("service call":
 * "Unsupported service call await state: …", "Service call returned invalid success JSON.").
 *
 * @internal
 *
 * @template-covariant T
 */
final readonly class ResultDecoder
{
    /** @param \Closure(NotificationResult): T $decode a result that is not a failure */
    private function __construct(
        private string $operation,
        private \Closure $decode,
    ) {
    }

    /**
     * A value decoded as JSON (objects as arrays); malformed JSON fails as "<Operation> returned invalid success JSON.".
     * An empty success (a handler without output) is null.
     *
     * @return self<mixed>
     */
    public static function json(string $operation, ?JournalValueCodec $codec = null): self
    {
        $codec ??= new NoopJournalValueCodec();

        return new self($operation, static fn (NotificationResult $result): mixed => match ($result->kind) {
            NotificationKind::Success => $result->bytes() === '' ? null : self::decodeJson($operation, $codec->decode($result->bytes())),
            NotificationKind::Void => null,
            default => throw self::unsupported($operation, $result),
        });
    }

    /**
     * A value deserialized by $serde; whatever the serializer throws propagates as is. An empty success is null for
     * the JSON serializers and reaches any other serializer as is.
     *
     * @return self<mixed>
     */
    public static function serde(string $operation, Serde $serde, ?JournalValueCodec $codec = null): self
    {
        $codec ??= new NoopJournalValueCodec();

        return new self($operation, static fn (NotificationResult $result): mixed => match ($result->kind) {
            NotificationKind::Success => $result->bytes() === '' && self::isJson($serde)
                ? null
                : $serde->deserialize($codec->decode($result->bytes())),
            NotificationKind::Void => null,
            default => throw self::unsupported($operation, $result),
        });
    }

    /**
     * json() for the JSON serializer, serde() for any other.
     *
     * @return self<mixed>
     */
    public static function value(string $operation, Serde $serde, ?JournalValueCodec $codec = null): self
    {
        return $serde instanceof JsonSerde ? self::json($operation, $codec) : self::serde($operation, $serde, $codec);
    }

    /**
     * No value: null, or the failure.
     *
     * @return self<null>
     */
    public static function void(string $operation): self
    {
        return new self($operation, static fn (NotificationResult $result): null => $result->kind === NotificationKind::Void
            ? null
            : throw self::unsupported($operation, $result));
    }

    /**
     * The state keys.
     *
     * @return self<list<string>>
     */
    public static function stateKeys(string $operation = 'state keys'): self
    {
        return new self($operation, static fn (NotificationResult $result): array => $result->kind === NotificationKind::StateKeys
            ? $result->keys()
            : throw self::unsupported($operation, $result));
    }

    /**
     * A new invocation's id.
     *
     * @return self<string>
     */
    public static function invocationId(string $operation = 'invocation ID'): self
    {
        return new self($operation, static fn (NotificationResult $result): string => $result->kind === NotificationKind::InvocationId
            ? $result->invocationIdValue()
            : throw self::unsupported($operation, $result));
    }

    /**
     * Awaits $handle, then takes and decodes its result.
     *
     * @return T
     *
     * @throws SuspensionException when the invocation suspended
     * @throws TerminalError when the invocation was cancelled (409) or the result is a failure
     */
    public function await(InvocationOperations $operations, int $handle, string $suspensionMessage): mixed
    {
        $this->assertAwaited($operations->doAwait($handle), $suspensionMessage);

        return $this->decode($operations->takeNotificationResult($handle));
    }

    /**
     * assertCompleted() for this operation.
     *
     * @throws SuspensionException when the invocation suspended
     * @throws TerminalError when the invocation received a cancel signal (409)
     */
    public function assertAwaited(AwaitResult $result, string $suspensionMessage): void
    {
        self::assertCompleted($result, $this->operation, $suspensionMessage);
    }

    /**
     * Returns when the await completed, and throws for every other state.
     *
     * @throws SuspensionException when the invocation suspended
     * @throws TerminalError when the invocation received a cancel signal (409)
     */
    public static function assertCompleted(AwaitResult $result, string $operation, string $suspensionMessage): void
    {
        match ($result->state) {
            AwaitState::Completed => null,
            AwaitState::Suspended => throw new SuspensionException($suspensionMessage),
            AwaitState::CancelSignalReceived => throw new TerminalError('Invocation cancelled', 409),
            default => throw new \RuntimeException("Unsupported {$operation} await state: {$result->state->value}"),
        };
    }

    /**
     * @return T
     *
     * @throws TerminalError when the result is a failure
     */
    public function decode(NotificationResult $result): mixed
    {
        if ($result->kind === NotificationKind::Failure) {
            throw $result->terminalError();
        }

        return ($this->decode)($result);
    }

    private static function decodeJson(string $operation, string $json): mixed
    {
        try {
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(ucfirst($operation) . ' returned invalid success JSON.', previous: $e);
        }
    }

    private static function isJson(Serde $serde): bool
    {
        return $serde instanceof JsonSerde || $serde instanceof MappedJsonSerde;
    }

    private static function unsupported(string $operation, NotificationResult $result): \RuntimeException
    {
        return new \RuntimeException("Unsupported {$operation} result state: {$result->kind->value}");
    }
}
