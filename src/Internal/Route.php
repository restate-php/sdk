<?php

declare(strict_types=1);

namespace Restate\Internal;

/**
 * What an endpoint request asks for, from its path (and, for discovery at `/`, its `Accept` header).
 *
 * @internal
 */
final readonly class Route
{
    private function __construct(
        public RouteKind $kind,
        public string $service = '',
        public string $handler = '',
        public ?string $key = null,
    ) {
    }

    public static function parse(string $path, string $accept = ''): self
    {
        $path = parse_url($path, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));

        if ($parts === [] && Discovery::acceptsAnyManifest($accept)) {
            return new self(RouteKind::Discover);
        }

        if ($parts === ['health']) {
            return new self(RouteKind::Health);
        }
        if ($parts === ['discover']) {
            return new self(RouteKind::Discover);
        }
        if (count($parts) === 3 && $parts[0] === 'invoke') {
            return new self(RouteKind::Invoke, $parts[1], $parts[2]);
        }
        if (count($parts) === 4 && $parts[0] === 'invoke') {
            return new self(RouteKind::Invoke, $parts[1], $parts[3], $parts[2]);
        }

        return new self(RouteKind::Unknown);
    }
}
