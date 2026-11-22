<?php

declare(strict_types=1);

namespace Restate\Internal\Backend;

/**
 * Checks the Restate request identity (the signed `x-restate-jwt-v1` token) of a request against the endpoint's
 * identity keys. Created once per endpoint by {@see \Restate\Internal\Backend::identityVerifier()} and reused for
 * every request it serves.
 *
 * @internal
 */
interface IdentityVerifier
{
    /**
     * @param list<array{0: string, 1: string}> $headers the request's headers (names in any case)
     * @param string $path the request path without the query; the verifier strips the endpoint's mount prefix
     *
     * @return ?string null when the request carries a valid identity, otherwise why it does not
     */
    public function verify(array $headers, string $path): ?string;
}
