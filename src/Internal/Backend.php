<?php

declare(strict_types=1);

namespace Restate\Internal;

use Restate\Internal\Backend\IdentityVerifier;
use Restate\Internal\Backend\Invocation;

/**
 * Creates one invocation per request, reports the service protocol range and creates the endpoint's request identity
 * verifier: the Backend Contract's endpoint half (see docs/BACKEND_ARCHITECTURE.md).
 *
 * @internal
 */
interface Backend
{
    /**
     * The service protocol versions this backend's VM negotiates, which discovery advertises.
     *
     * @return array{min: int, max: int}
     *
     * @throws BackendException
     */
    public function protocolVersions(): array;

    /**
     * @param list<array{0: string, 1: string}> $headers
     * @param ?CoreLogForwarder $coreLogs captures the VM's shared-core log events at its level and forwards them after
     *                                     every VM call; null: capture nothing
     */
    public function newInvocation(array $headers, ?CoreLogForwarder $coreLogs = null): Invocation;

    /**
     * The verifier for these identity keys (Restate's `publickeyv1_…` format).
     *
     * @param non-empty-list<string> $identityKeys
     *
     * @throws \InvalidArgumentException when a key is malformed
     * @throws BackendException
     */
    public function identityVerifier(array $identityKeys): IdentityVerifier;
}
