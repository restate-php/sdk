# Restate PHP SDK

Build [Restate](https://restate.dev) services in PHP: handlers whose calls, side effects, state, timers and promises Restate journals, so they run to completion across failures and restarts. `restate/sdk` is the package you serve with: Services, Virtual Objects and Workflows as attributed PHP classes or builders, the handler contexts, and the endpoint that serves them under `php -S`, PHP-FPM, FrankenPHP or any PSR-15 framework. All Restate traffic goes through the Restate PHP extension, which embeds [`restate-sdk-shared-core`](https://crates.io/crates/restate-sdk-shared-core), the core of the official TypeScript, Python and Rust SDKs.

> [!NOTE]
> A community-maintained SDK: not an official Restate SDK, and not supported by Restate.

## Installation

```bash
pie install restate/ext-restate
composer require restate/sdk
```

The package requires the extension (`ext-restate`) of its own minor version, so Composer refuses to install it without; [PIE](https://github.com/php/pie) installs a prebuilt binary ([`restate/ext-restate`](https://github.com/restate-php/ext-restate)).

## Usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Restate\Attributes as Restate;
use Restate\Context;
use Restate\Endpoint;

#[Restate\Service('Greeter')]
final class Greeter
{
    #[Restate\Handler]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }
}

Endpoint::serve([new Greeter()]);
```

The [Docker quickstart](https://github.com/restate-php/restate/blob/main/examples/quickstart/README.md) runs a service like this one with a Restate server and walks through calling it, failing it and killing it mid-request; the [serving guide](https://github.com/restate-php/restate/blob/main/docs/serving.md) covers PHP-FPM, FrankenPHP and PSR-15.

Related packages: [`restate/ingress`](https://github.com/restate-php/ingress) (call services from code that is not a handler), [`restate/testing`](https://github.com/restate-php/testing) (test harness).

## Requirements

- PHP 8.4, 8.5 or 8.6, NTS or ZTS
- The Restate PHP extension, `ext-restate`
- Restate Server 1.8 or newer

## Documentation

See the [documentation](https://github.com/restate-php/restate/blob/main/docs/README.md): [installation](https://github.com/restate-php/restate/blob/main/docs/installation.md), [writing services](https://github.com/restate-php/restate/blob/main/docs/develop/services.md), [serving](https://github.com/restate-php/restate/blob/main/docs/serving.md) and [testing](https://github.com/restate-php/restate/blob/main/docs/develop/testing.md).

This repository is a read-only subtree split of [restate-php/restate](https://github.com/restate-php/restate). Report issues and send pull requests there.

## License

MIT; see [LICENSE](LICENSE).
