<?php

declare(strict_types=1);

namespace Restate\Internal;

use InvalidArgumentException;
use Restate\HandlerDefinition;
use Restate\InvocationConfiguration;
use Restate\InvocationRetryPolicy;
use Restate\JsonPayloadOptions;
use Restate\Service;
use Restate\ServiceDefinition;

/**
 * Builds the discovery manifest for an endpoint's services and negotiates its version.
 *
 * @internal
 */
final class Discovery
{
    /** The oldest discovery manifest version this SDK serves. */
    public const int MIN_MANIFEST_VERSION = 2;

    /**
     * The newest discovery manifest version this SDK serves. v5 (Restate 1.8) only adds the optional
     * `eagerStateKeysWhitelist` (a state preload key list, `InvocationConfiguration::$statePreload`).
     */
    public const int MAX_MANIFEST_VERSION = 5;

    /**
     * @param array{min: int, max: int} $protocolVersions the service protocol versions the backend's VM negotiates
     *                                                   (`Restate\Internal\Backend::protocolVersions()`)
     */
    public static function manifestJson(ServiceRegistry $registry, array $protocolVersions, int $manifestVersion = self::MAX_MANIFEST_VERSION, ?JsonPayloadOptions $jsonPayloads = null): string
    {
        if ($manifestVersion < self::MIN_MANIFEST_VERSION || $manifestVersion > self::MAX_MANIFEST_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Discovery manifest version %d is not supported; this SDK implements versions %d to %d.',
                $manifestVersion,
                self::MIN_MANIFEST_VERSION,
                self::MAX_MANIFEST_VERSION,
            ));
        }

        $services = [];
        $includeDefinitionMetadata = $manifestVersion >= 3;
        $includeRetryPolicy = $manifestVersion >= 4;

        foreach ($registry->services() as $service) {
            self::assertRetryPolicyManifestVersion($manifestVersion, "Service {$service->name}", $service->invocationRetryPolicy);
            self::assertInvocationConfigurationManifestVersion($manifestVersion, "Service {$service->name}", $service->invocationConfiguration);
            self::assertStatePreloadManifestVersion($manifestVersion, "Service {$service->name}", $service->invocationConfiguration);
            self::assertServiceInvocationConfiguration($service);
            $handlers = [];
            foreach ($service->handlers as $handler) {
                self::assertRetryPolicyManifestVersion($manifestVersion, "Handler {$service->name}/{$handler->name}", $handler->invocationRetryPolicy);
                self::assertInvocationConfigurationManifestVersion($manifestVersion, "Handler {$service->name}/{$handler->name}", $handler->invocationConfiguration);
                self::assertStatePreloadManifestVersion($manifestVersion, "Handler {$service->name}/{$handler->name}", $handler->invocationConfiguration);
                self::assertHandlerInvocationConfiguration($service, $handler);
                $handlerPayload = ['name' => $handler->name];
                if ($includeDefinitionMetadata && $handler->documentation !== null) {
                    $handlerPayload['documentation'] = $handler->documentation;
                }
                if ($includeDefinitionMetadata && $handler->metadata !== []) {
                    $handlerPayload['metadata'] = $handler->metadata;
                }
                if ($includeRetryPolicy) {
                    $handlerPayload = [...$handlerPayload, ...self::retryPolicyFields($handler->invocationRetryPolicy)];
                }
                $handlerPayload = [...$handlerPayload, ...self::invocationConfigurationFields($handler->invocationConfiguration)];
                if ($handler->type !== null) {
                    $handlerPayload['ty'] = $handler->type;
                }
                $handlerPayload['input'] = $registry->effectiveHandlerInputPayload($service, $handler, $jsonPayloads)->manifest();
                $handlerPayload['output'] = $registry->effectiveHandlerOutputPayload($service, $handler, $jsonPayloads)->manifest();
                $handlers[] = $handlerPayload;
            }

            $servicePayload = [
                'name' => $service->name,
                'ty' => $service->type,
                'handlers' => $handlers,
            ];
            if ($includeDefinitionMetadata && $service->documentation !== null) {
                $servicePayload['documentation'] = $service->documentation;
            }
            if ($includeDefinitionMetadata && $service->metadata !== []) {
                $servicePayload['metadata'] = $service->metadata;
            }
            if ($includeRetryPolicy) {
                $servicePayload = [...$servicePayload, ...self::retryPolicyFields($service->invocationRetryPolicy)];
            }
            $servicePayload = [...$servicePayload, ...self::invocationConfigurationFields($service->invocationConfiguration)];

            $services[] = $servicePayload;
        }

        return json_encode([
            'protocolMode' => 'REQUEST_RESPONSE',
            'minProtocolVersion' => $protocolVersions['min'],
            'maxProtocolVersion' => $protocolVersions['max'],
            'services' => $services,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, int|float|string> */
    private static function retryPolicyFields(?InvocationRetryPolicy $retryPolicy): array
    {
        return $retryPolicy?->manifestFields() ?? [];
    }

    private static function assertRetryPolicyManifestVersion(int $manifestVersion, string $subject, ?InvocationRetryPolicy $retryPolicy): void
    {
        if ($manifestVersion >= 4 || self::retryPolicyFields($retryPolicy) === []) {
            return;
        }

        throw new InvalidArgumentException("{$subject} invocation retry policy requires discovery manifest version 4.");
    }

    /** @return array<string, int|bool|list<string>> */
    private static function invocationConfigurationFields(?InvocationConfiguration $configuration): array
    {
        return $configuration?->manifestFields() ?? [];
    }

    private static function assertInvocationConfigurationManifestVersion(int $manifestVersion, string $subject, ?InvocationConfiguration $configuration): void
    {
        if ($manifestVersion >= 3 || self::invocationConfigurationFields($configuration) === []) {
            return;
        }

        throw new InvalidArgumentException("{$subject} invocation configuration requires discovery manifest version 3.");
    }

    /**
     * A preload key list (`eagerStateKeysWhitelist`) is new in manifest v5 (Restate 1.8). Like the TypeScript and Python
     * SDKs, discovery fails rather than silently preloading nothing on an older server.
     */
    private static function assertStatePreloadManifestVersion(int $manifestVersion, string $subject, ?InvocationConfiguration $configuration): void
    {
        if ($manifestVersion >= 5 || $configuration === null || !$configuration->preloadsStateKeys()) {
            return;
        }

        throw new InvalidArgumentException("{$subject} preloads a list of state keys, which needs discovery manifest version 5 (Restate 1.8 or newer); this server negotiated version {$manifestVersion}. Upgrade Restate, or preload all state (true) or none (false).");
    }

    private static function assertServiceInvocationConfiguration(ServiceDefinition $service): void
    {
        if ($service->invocationConfiguration?->workflowCompletionRetentionMillis !== null) {
            throw new InvalidArgumentException("Service {$service->name} workflow completion retention is valid only for workflow handlers.");
        }
    }

    private static function assertHandlerInvocationConfiguration(ServiceDefinition $service, HandlerDefinition $handler): void
    {
        $configuration = $handler->invocationConfiguration;
        if ($configuration === null) {
            return;
        }

        $subject = "Handler {$service->name}/{$handler->name}";
        if ($configuration->workflowCompletionRetentionMillis !== null && $handler->type !== HandlerDefinition::TYPE_WORKFLOW) {
            throw new InvalidArgumentException("{$subject} workflow completion retention is valid only for workflow handlers.");
        }
        if ($configuration->idempotencyRetentionMillis !== null && $handler->type === HandlerDefinition::TYPE_WORKFLOW) {
            throw new InvalidArgumentException("{$subject} idempotency retention is not valid for workflow handlers.");
        }
    }

    /**
     * The manifest version to answer a discovery request with: the newest one the server accepts (Restate lists every
     * version it reads in the `Accept` header) that this SDK implements, or null when there is none.
     */
    public static function manifestVersion(string $acceptHeader): ?int
    {
        for ($version = self::MAX_MANIFEST_VERSION; $version >= self::MIN_MANIFEST_VERSION; $version--) {
            if (self::accepts($acceptHeader, self::contentType($version))) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Whether the `Accept` header names any discovery manifest version, supported or not: a request for `/` that does
     * is a discovery request.
     */
    public static function acceptsAnyManifest(string $acceptHeader): bool
    {
        return self::accepts($acceptHeader, 'application/vnd.restate.endpointmanifest');
    }

    /** Media types are case-insensitive (RFC 9110, section 8.3.1), so is this match. */
    private static function accepts(string $acceptHeader, string $mediaType): bool
    {
        return str_contains(strtolower($acceptHeader), strtolower($mediaType));
    }

    public static function contentType(int $manifestVersion): string
    {
        return "application/vnd.restate.endpointmanifest.v{$manifestVersion}+json";
    }
}
