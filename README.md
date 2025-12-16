# Mbuzz PHP SDK

Multi-touch attribution SDK for PHP. Framework-agnostic design with optional adapters for Laravel, Symfony, and other frameworks.

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

// Initialize from request (reads cookies, creates session)
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
$sessionId = Mbuzz::sessionId();
$userId = Mbuzz::userId();
```

## Configuration Options

```php
Mbuzz::init([
    'api_key' => 'sk_live_...',           // Required: Your Mbuzz API key
    'api_url' => 'https://mbuzz.co/api/v1', // Optional: API URL (for self-hosted)
    'enabled' => true,                      // Optional: Enable/disable tracking
    'debug' => false,                       // Optional: Log API requests
    'timeout' => 5,                         // Optional: HTTP timeout in seconds
    'skip_paths' => ['/admin'],             // Optional: Additional paths to skip
    'skip_extensions' => ['.pdf'],          // Optional: Additional extensions to skip
]);
```

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
// config/app.php - The service provider auto-registers via composer

// config/mbuzz.php (publish with: php artisan vendor:publish --tag=mbuzz-config)
return [
    'api_key' => env('MBUZZ_API_KEY'),
    'enabled' => env('MBUZZ_ENABLED', true),
    'debug' => env('MBUZZ_DEBUG', false),
];

// app/Http/Kernel.php
protected $middleware = [
    // ...
    \Mbuzz\Adapter\LaravelMiddleware::class,
];
```

### Slim / PSR-15 Frameworks

```php
<?php

use Slim\Factory\AppFactory;
use Mbuzz\Mbuzz;
use Mbuzz\Middleware\TrackingMiddleware;

$app = AppFactory::create();

Mbuzz::init(['api_key' => $_ENV['MBUZZ_API_KEY']]);
$app->add(new TrackingMiddleware());

$app->run();
```

## API Reference

### `Mbuzz::init(array $options)`

Initialize the SDK. Must be called before any tracking methods.

### `Mbuzz::initFromRequest()`

Initialize context from the current HTTP request. Reads cookies, creates visitor/session IDs if needed, and creates a session in Mbuzz.

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

### `Mbuzz::visitorId()`, `Mbuzz::sessionId()`, `Mbuzz::userId()`

Get current tracking IDs.

### `Mbuzz::reset()`

Reset SDK state. Useful for testing or long-running processes.

## Cookie Behavior

The SDK sets two cookies:
- `_mbuzz_vid`: Visitor ID (2-year expiry)
- `_mbuzz_sid`: Session ID (30-minute sliding expiry)

Both cookies are:
- HttpOnly (not accessible via JavaScript)
- SameSite=Lax
- Secure (on HTTPS connections)

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
