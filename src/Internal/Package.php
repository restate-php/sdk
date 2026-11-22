<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * @internal
 */
final class Package
{
    /** This package's Composer name. */
    public const string NAME = 'restate/sdk';

    private function __construct()
    {
    }

    /**
     * The installed version as Composer reports it, or null when Composer's runtime API does not know it. When another
     * package replaces this one (the SDK's own repository replaces every package with its root, at its own version),
     * that is the version of the replacement.
     */
    public static function version(): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return null;
        }

        foreach (\Composer\InstalledVersions::getAllRawData() as $installed) {
            $package = $installed['versions'][self::NAME] ?? null;
            if ($package !== null) {
                return $package['pretty_version'] ?? $package['replaced'][0] ?? null;
            }
        }

        return null;
    }
}
