<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * Ends the current invocation attempt because the invocation suspended (or a `Context::run` retry was scheduled).
 *
 * It extends `\Error`, not `\Exception`, so a handler's `catch (\Exception)` cannot swallow it. Handlers must not
 * catch `\Throwable` around context calls either; if they do, every later context operation in the attempt throws
 * this again (see {@see SuspensionGuard}) and the endpoint ignores whatever the handler returns or throws.
 *
 * @internal
 */
final class SuspensionException extends \Error
{
}
