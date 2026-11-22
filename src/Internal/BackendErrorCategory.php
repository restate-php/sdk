<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * What kind of failure a BackendException is, independent of its message. The names are the Rust core's error
 * categories (`restate_php_core::ErrorCategory`), which the extension reports as `ExtensionException::$category`.
 *
 * @internal
 */
enum BackendErrorCategory: string
{
    /** The SDK broke the extension contract: an argument out of range, a malformed structured argument, an unknown handle. */
    case InvalidArgument = 'invalid_argument';

    /** The invocation suspended; the attempt must stop without output. */
    case Suspended = 'suspended';

    /** Restate sent something the protocol does not allow, or the protocol version or content type is not supported. */
    case Protocol = 'protocol';

    /** The handler did something different from what the journal recorded. */
    case JournalMismatch = 'journal_mismatch';

    /** The VM is already closed (ended, failed or suspended) and refuses further operations. */
    case Closed = 'closed';

    /** Anything else: a shared-core internal error, a Rust panic, or a result the bridge did not expect. */
    case Internal = 'internal';

    /** The category an ExtensionException reports; a name this package does not know (a newer extension) is Internal. */
    public static function fromExtension(string $category): self
    {
        return self::tryFrom($category) ?? self::Internal;
    }
}
