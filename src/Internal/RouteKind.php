<?php

declare(strict_types=1);

namespace Restate\Internal;

/** @internal */
enum RouteKind
{
    case Health;
    case Discover;
    case Invoke;
    case Unknown;
}
