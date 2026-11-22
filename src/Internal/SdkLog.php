<?php

declare(strict_types=1);

namespace Restate\Internal;

use Psr\Log\LoggerInterface;
use Restate\Request;

/**
 * How the SDK logs its own work to the endpoint's logger: the PSR-3 context keys it puts on its records, and a write
 * that cannot fail the request (docs/decisions/0027-sdk-logging.md).
 *
 * @internal
 */
final class SdkLog
{
    public const string INVOCATION_ID = 'restate.invocation.id';

    /** `Service/handler`, or `Service/key/handler` for a virtual object or workflow, as the official SDKs log it. */
    public const string INVOCATION_TARGET = 'restate.invocation.target';

    /** The request path, on records about a request that is not (yet) an invocation. */
    public const string REQUEST_PATH = 'restate.request.path';

    public const string RUN_NAME = 'restate.run.name';

    /** On records about output PHP printed outside the protocol response: its size in bytes. */
    public const string OUTPUT_BYTES = 'restate.output.bytes';

    /** Where that output started: `file:line`, or a description when PHP printed it before the script ran. */
    public const string OUTPUT_ORIGIN = 'restate.output.origin';

    /** Its first bytes. */
    public const string OUTPUT_EXCERPT = 'restate.output.excerpt';

    /** The invocation id header Restate sends with every invocation request. */
    private const string INVOCATION_ID_HEADER = 'x-restate-invocation-id';

    /** @return array{'restate.invocation.id': string, 'restate.invocation.target': string} */
    public static function invocation(Request $request): array
    {
        return self::of($request->id, self::target($request->target->serviceName(), $request->target->key(), $request->target->handlerName()));
    }

    /** @return array{'restate.invocation.id': string, 'restate.invocation.target': string} */
    public static function of(string $invocationId, string $target): array
    {
        return [self::INVOCATION_ID => $invocationId, self::INVOCATION_TARGET => $target];
    }

    /** `Service/handler`, or `Service/key/handler` when the key is not empty. */
    public static function target(string $service, ?string $key, string $handler): string
    {
        return $key === null || $key === '' ? "{$service}/{$handler}" : "{$service}/{$key}/{$handler}";
    }

    /**
     * The context of a record about a request before (or without) its invocation input: the invocation id Restate
     * sent as a header, if any, and the path.
     *
     * @param list<array{0: string, 1: string}> $headers
     *
     * @return array<string, string>
     */
    public static function request(string $requestUri, array $headers): array
    {
        $context = self::headerInvocationId($headers);
        $path = parse_url($requestUri, PHP_URL_PATH);
        $context[self::REQUEST_PATH] = is_string($path) ? $path : $requestUri;

        return $context;
    }

    /**
     * The context of a record about an invocation attempt before its input is read: the invocation id Restate sent as a
     * header, if any, and the target without its key.
     *
     * @param list<array{0: string, 1: string}> $headers
     *
     * @return array<string, string>
     */
    public static function beforeInput(array $headers, string $service, string $handler): array
    {
        return self::headerInvocationId($headers) + [self::INVOCATION_TARGET => self::target($service, null, $handler)];
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     *
     * @return array<string, string>
     */
    private static function headerInvocationId(array $headers): array
    {
        $invocationId = array_find($headers, static fn (array $header): bool => $header[0] === self::INVOCATION_ID_HEADER)[1] ?? null;

        return $invocationId === null || $invocationId === '' ? [] : [self::INVOCATION_ID => $invocationId];
    }

    /**
     * Logs a record of the SDK's own. A logger that throws loses the record but never fails the request: the endpoint
     * promises a response whatever happens.
     *
     * @param array<string, mixed> $context
     */
    public static function write(LoggerInterface $logger, string $level, string $message, array $context): void
    {
        try {
            $logger->log($level, $message, $context);
        } catch (\Throwable) {
        }
    }
}
