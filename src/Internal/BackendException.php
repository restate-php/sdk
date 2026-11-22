<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * A failure of the extension backend. `category()` says what kind of failure it is; `getCode()` is the shared-core error
 * code when the failure came from shared-core (for example 415, 570, 598), otherwise 0.
 *
 * Failures the PHP adapter raises itself (an unexpected result shape, a freed VM, a missing or mismatched extension)
 * are Internal.
 *
 * @internal
 */
final class BackendException extends \RuntimeException implements \Restate\Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly BackendErrorCategory $category = BackendErrorCategory::Internal,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function category(): BackendErrorCategory
    {
        return $this->category;
    }
}
