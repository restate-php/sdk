<?php

declare(strict_types=1);

namespace Restate\Internal\Backend\Extension;

use Restate\Extension\ExtensionException;
use Restate\Extension\IdentityVerifier as ExtensionIdentityVerifier;
use Restate\Internal\Backend\IdentityVerifier as IdentityVerifierContract;

/**
 * The request identity verifier on the PHP extension: sdk-shared-core's `IdentityVerifier`, the one the official
 * TypeScript and Python SDKs use, owned by this object (`Restate\Extension\IdentityVerifier`).
 *
 * @internal
 */
final readonly class IdentityVerifier implements IdentityVerifierContract
{
    public function __construct(private ExtensionIdentityVerifier $verifier)
    {
    }

    #[\Override]
    public function verify(array $headers, string $path): ?string
    {
        try {
            return $this->verifier->verify($headers, $path);
        } catch (ExtensionException $e) {
            throw Invocation::backendException($e);
        }
    }
}
