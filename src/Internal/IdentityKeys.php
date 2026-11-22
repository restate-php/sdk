<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * Checks an endpoint's configured request identity keys. Not configuring keys turns verification off; configuring
 * none, or a key that is `false` or empty, is rejected, so a missing environment variable cannot silently turn it off.
 *
 * @internal
 */
final class IdentityKeys
{
    /**
     * @param iterable<mixed>|null $identityKeys null: not configured
     *
     * @return non-empty-list<string>|null null: verification off
     *
     * @throws \InvalidArgumentException
     */
    public static function of(?iterable $identityKeys): ?array
    {
        if ($identityKeys === null) {
            return null;
        }

        $keys = [];
        foreach ($identityKeys as $identityKey) {
            if ($identityKey === false) {
                throw new \InvalidArgumentException('A request identity key is false, as getenv() returns for an environment variable that is not set. Set the variable (php-fpm hides the environment unless clear_env = no), or do not configure identity keys to accept unsigned requests.');
            }
            if (!is_string($identityKey)) {
                throw new \InvalidArgumentException('Request identity keys must be strings.');
            }
            if ($identityKey === '') {
                throw new \InvalidArgumentException('A request identity key is empty. Configure a publickeyv1_… key, or do not configure identity keys to accept unsigned requests.');
            }
            $keys[] = $identityKey;
        }

        if ($keys === []) {
            throw new \InvalidArgumentException('No request identity keys given. Configure at least one publickeyv1_… key, or do not configure identity keys to accept unsigned requests.');
        }

        return $keys;
    }
}
