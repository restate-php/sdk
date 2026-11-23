<?php

declare(strict_types=1);

namespace Restate\Internal\Backend\Extension;

use Restate\Extension\ExtensionException;
use Restate\Extension\IdentityVerifier as ExtensionIdentityVerifier;
use Restate\Extension\Vm;
use Restate\Internal\Backend as BackendContract;
use Restate\Internal\Backend\IdentityVerifier as IdentityVerifierContract;
use Restate\Internal\Backend\Invocation as InvocationContract;
use Restate\Internal\BackendErrorCategory;
use Restate\Internal\BackendException;
use Restate\Internal\CoreLogForwarder;
use Restate\Internal\Package;

/**
 * The PHP extension backend (module `restate`, `ext-restate` to Composer).
 *
 * The extension and this package are installed and upgraded separately, so construction checks the version handshake:
 * the extension's contract version must be exactly the one this package was written for.
 *
 * @internal
 */
final class Backend implements BackendContract
{
    /** The PHP module name, as `extension_loaded()` and `php -m` know it. */
    public const string MODULE_NAME = 'restate';

    /**
     * The extension contract this package speaks (`Restate\Extension\contract_version()`; see extension/restate.stub.php).
     * 1 until the first extension release; after that it changes only together with `CONTRACT_VERSION` in
     * extension/extension/src/lib.rs, by the rules in RELEASING.md ("Bumping versions").
     */
    public const int CONTRACT_VERSION = 1;

    /**
     * How to install or upgrade the extension, after "Install it" or "Upgrade the extension". PIE installs the prebuilt
     * binaries; building from source is for the platforms they do not cover.
     */
    private const string INSTALL_HINT = 'with PIE (`pie install restate/ext-restate`), which also enables it '
        . '(`extension=restate` in php.ini); where PIE has no prebuilt binary, build it from source as '
        . 'https://github.com/restate-php/restate/blob/main/docs/installation.md#building-from-source describes';

    /** @throws BackendException when the extension is not loaded or speaks another contract version */
    public function __construct()
    {
        if (!self::isLoaded()) {
            throw new BackendException(sprintf(
                'The Restate PHP extension (%s) is not loaded, and %s. Install it %s.',
                self::MODULE_NAME,
                self::requirement(),
                self::INSTALL_HINT,
            ));
        }

        self::assertCompatible(\Restate\Extension\version(), \Restate\Extension\contract_version());
    }

    public static function isLoaded(): bool
    {
        return extension_loaded(self::MODULE_NAME);
    }

    /**
     * Fails unless the extension speaks this package's contract version.
     *
     * @throws BackendException naming both versions and how to fix the mismatch
     */
    public static function assertCompatible(string $extensionVersion, int $contractVersion): void
    {
        if ($contractVersion === self::CONTRACT_VERSION) {
            return;
        }

        $required = self::requirement();

        throw new BackendException($contractVersion < self::CONTRACT_VERSION
            ? sprintf(
                'The loaded Restate PHP extension %s implements contract version %d, but %s. Upgrade the extension %s.',
                $extensionVersion,
                $contractVersion,
                $required,
                self::INSTALL_HINT,
            )
            : sprintf(
                'The loaded Restate PHP extension %s implements contract version %d, but %s. The extension is newer than '
                . 'this package: upgrade %s, or install the extension version that matches it %s.',
                $extensionVersion,
                $contractVersion,
                $required,
                Package::NAME,
                self::INSTALL_HINT,
            ));
    }

    /** "<package> [<version>] requires Restate PHP extension contract version <n>" */
    private static function requirement(): string
    {
        $package = Package::NAME . (($version = Package::version()) !== null ? " {$version}" : '');

        return sprintf('%s requires Restate PHP extension contract version %d', $package, self::CONTRACT_VERSION);
    }

    /** @return array{min: int, max: int} shared-core's supported range (`Restate\Extension\protocol_versions()`) */
    #[\Override]
    public function protocolVersions(): array
    {
        try {
            return \Restate\Extension\protocol_versions();
        } catch (ExtensionException $e) {
            throw Invocation::backendException($e);
        }
    }

    /** @param list<array{0: string, 1: string}> $headers */
    #[\Override]
    public function newInvocation(array $headers, ?CoreLogForwarder $coreLogs = null): InvocationContract
    {
        try {
            return new Invocation(new Vm($headers, $coreLogs?->level), $coreLogs);
        } catch (ExtensionException $e) {
            throw Invocation::backendException($e);
        }
    }

    /** @param non-empty-list<string> $identityKeys */
    #[\Override]
    public function identityVerifier(array $identityKeys): IdentityVerifierContract
    {
        try {
            return new IdentityVerifier(new ExtensionIdentityVerifier($identityKeys));
        } catch (ExtensionException $e) {
            throw BackendErrorCategory::fromExtension($e->category) === BackendErrorCategory::InvalidArgument
                ? new \InvalidArgumentException($e->getMessage(), 0, $e)
                : Invocation::backendException($e);
        }
    }
}
