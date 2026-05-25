# Mbuzz PHP SDK

Multi-touch attribution SDK for PHP. Framework-agnostic core with first-party
adapters for Laravel, Symfony, and any PSR-15 framework (Slim, Mezzio, …).

## Requirements

- PHP 8.1+
- ext-curl
- ext-json

## Installation

```bash
composer require mbuzz/mbuzz-php
```

## Quick Start

```php
<?php

use Mbuzz\Mbuzz;

// Initialize the SDK (typically in your bootstrap/config)
Mbuzz::init([
    'api_key' => $_ENV['MBUZZ_API_KEY'],
    'debug' => true, // optional, logs API requests
]);

// Initialize from request (reads cookies, captures context)
// Call this early in your request lifecycle, before output
Mbuzz::initFromRequest();

// Track events
Mbuzz::event('page_view', ['url' => 'https://example.com/products']);
Mbuzz::event('add_to_cart', ['product_id' => 'SKU-123', 'price' => 49.99]);

// Track conversions
Mbuzz::conversion('purchase', [
    'revenue' => 99.99,
    'properties' => ['order_id' => 'ORD-123'],
]);

// Acquisition conversion (marks signup as first touchpoint)
Mbuzz::conversion('signup', [
    'user_id' => $user->id,
    'is_acquisition' => true,
]);

// Recurring revenue (inherits attribution from acquisition)
Mbuzz::conversion('payment', [
    'user_id' => $user->id,
    'revenue' => 49.00,
    'inherit_acquisition' => true,
]);

// Identify user (link visitor to known user)
Mbuzz::identify($user->id, [
    'email' => $user->email,
    'name' => $user->name,
    'plan' => 'pro',
]);

// Access current IDs
$visitorId = Mbuzz::visitorId();
$userId = Mbuzz::userId();
```

## Configuration Options

```php
Mbuzz::init([
    'api_key' => 'sk_live_...',           // Required: Your Mbuzz API key
    'enabled' => true,                      // Optional: Enable/disable tracking
    'debug' => false,                       // Optional: Log API requests
    'timeout' => 5,                         // Optional: HTTP timeout in seconds
    'skip_paths' => ['/admin'],             // Optional: Additional paths to skip
    'skip_extensions' => ['.pdf'],          // Optional: Additional extensions to skip
]);
```

The API URL is fixed at `https://api.mbuzz.co/api/v1` — all traffic routes
through the edge ingest proxy.

## Non-blocking dispatch

Fire-and-forget tracking calls (`Mbuzz::initFromRequest()` session creates,
explicit `Api::post`) are queued and flushed in the PHP shutdown phase. On
FPM and LiteSpeed the SDK calls `fastcgi_finish_request` /
`litespeed_finish_request` first, so the user receives the response before
the tracking POST goes out — page-render latency is unaffected even when
the API is slow. On environments without FPM (CLI workers, plain CGI) the
queue still flushes in shutdown but synchronously; the session POST keeps a
tight 2-second cap as a backstop.

`Mbuzz::event()`, `Mbuzz::conversion()`, and `Mbuzz::identify()` remain
synchronous because callers want the response (event_id, conversion_id,
attribution).

## Framework Integration

### Plain PHP

```php
<?php
// index.php or bootstrap.php

require 'vendor/autoload.php';

use Mbuzz\Mbuzz;

Mbuzz::init(['api_key' => $_ENV['MBUZZ_API_KEY']]);
Mbuzz::initFromRequest();

// Your application code...
```

### Laravel

```php
// app/Providers/AppServiceProvider.php
use Mbuzz\Mbuzz;

public function boot(): void
{
    Mbuzz::init([
        'api_key' => config('services.mbuzz.key'),
        'debug' => config('app.debug'),
    ]);
}

// app/Http/Kernel.php
protected $middleware = [
    // ...
    \Mbuzz\Adapter\LaravelMiddleware::class,
];
```

The middleware is duck-typed against Laravel's `handle($request, Closure $next)`
contract and never imports an Illuminate class. A dedicated service provider /
config publisher is not shipped yet — wire `Mbuzz::init()` into a provider you
already own.

### Symfony

```php
<?php
// config/services.yaml
services:
    Mbuzz\Adapter\SymfonySubscriber:
        tags: ['kernel.event_subscriber']

// src/Kernel.php or config/packages/mbuzz.php
use Mbuzz\Mbuzz;

Mbuzz::init([
    'api_key' => $_ENV['MBUZZ_API_KEY'],
]);
```

The `SymfonySubscriber` automatically initializes tracking on each request by listening to the `kernel.request` event with high priority.

### Slim / PSR-15 Frameworks

Add `psr/http-server-middleware` to your project (Slim and Mezzio already
require it transitively):

```bash
composer require psr/http-server-middleware
```

Then wire it in:

```php
<?php

use Slim\Factory\AppFactory;
use Mbuzz\Mbuzz;
use Mbuzz\Adapter\Psr15Middleware;

$app = AppFactory::create();

Mbuzz::init(['api_key' => $_ENV['MBUZZ_API_KEY']]);
$app->add(new Psr15Middleware());

$app->run();
```

The same middleware works for Mezzio, Hyperf, and any other PSR-15
compliant framework.

### WordPress

A dedicated WordPress plugin (with WooCommerce conversion hooks) is on the
roadmap — see [`lib/specs/wordpress-plugin.md`](lib/specs/wordpress-plugin.md).
Until it ships, drop the SDK into a small `mu-plugin` that calls
`Mbuzz::init()` on `plugins_loaded` and `Mbuzz::initFromRequest()` on
`template_redirect`.

## API Reference

### `Mbuzz::init(array $options)`

Initialize the SDK. Must be called before any tracking methods.

### `Mbuzz::initFromRequest()`

Initialize context from the current HTTP request. Reads visitor cookie, captures IP and user agent for server-side session resolution.

### `Mbuzz::event(string $eventType, array $properties = [])`

Track an event. Returns result array with `event_id` on success, `false` on failure.

### `Mbuzz::conversion(string $conversionType, array $options = [])`

Track a conversion. Options:
- `revenue` (float): Conversion value
- `user_id` (string): User ID
- `is_acquisition` (bool): Mark as acquisition conversion
- `inherit_acquisition` (bool): Inherit attribution from acquisition
- `properties` (array): Custom properties

Returns result array with `conversion_id` on success, `false` on failure.

### `Mbuzz::identify(string|int $userId, array $traits = [])`

Link the current visitor to a known user. Returns `true` on success.

### `Mbuzz::visitorId()`, `Mbuzz::userId()`

Get current tracking IDs.

### `Mbuzz::reset()`

Reset SDK state. Useful for testing or long-running processes.

## Cookie Behavior

The SDK sets one cookie:
- `_mbuzz_vid`: Visitor ID (2-year expiry)

The cookie is:
- HttpOnly (not accessible via JavaScript)
- SameSite=Lax
- Secure (on HTTPS connections)

Session resolution is handled server-side using IP and user agent for device fingerprinting.

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run specific test suite
composer test:unit
composer test:integration
```

## License

MIT
