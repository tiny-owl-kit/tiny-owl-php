# TinyOwl PHP SDK

[![Packagist](https://img.shields.io/packagist/v/tiny-owl-kit/observability.svg)](https://packagist.org/packages/tiny-owl-kit/observability)
[![PHP](https://img.shields.io/packagist/php-v/tiny-owl-kit/observability)](https://packagist.org/packages/tiny-owl-kit/observability)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Official PHP SDK for [TinyOwl](https://tiny-owl-kit.io) — lightweight observability and event logging with enterprise-grade HMAC-SHA256 security.

## Installation

```bash
composer require tiny-owl-kit/observability
```

## Quick start

```php
use TinyOwl\Observability\TinyOwl;

$client = new TinyOwl([
    'apiKey'        => getenv('TINYOWL_API_KEY'),
    'projectSecret' => getenv('TINYOWL_PROJECT_SECRET'),
]);

$client->info('App started', ['version' => '1.0.0']);
$client->warning('Disk space low', ['availableGb' => 1.2]);
$client->error('Payment failed', ['orderId' => 'ORD-9', 'reason' => 'declined']);
```

## Configuration

| Parameter        | Type     | Default                          | Description                                      |
| ---------------- | -------- | -------------------------------- | ------------------------------------------------ |
| `apiKey`         | `string` | **required**                     | Your project API key from the TinyOwl dashboard. |
| `projectSecret`  | `string` | **required**                     | Your project secret for HMAC signing.            |
| `baseUrl`        | `string` | `https://be.tiny-owl-kit.io/api` | TinyOwl API base URL.                            |
| `timeout`        | `float`  | `5.0`                            | HTTP request timeout in seconds.                 |
| `autoTraceId`    | `bool`   | `true`                           | Attach a stable UUID v4 trace ID to every event. |
| `defaultContext` | `array`  | `[]`                             | Key/value pairs merged into every log call.      |

## Logging events

```php
$client->info('User signed in', ['userId' => 'u-123']);
$client->warning('Rate limit approaching', ['pct' => 90]);
$client->error('Database unreachable', ['host' => 'db.prod']);

$client->log('Order created', 'info', ['orderId' => 'ORD-1']);
```

## Scoped loggers with `withContext()`

```php
$reqLogger = $client->withContext(['requestId' => 'req-xyz', 'userId' => 'u-123']);
$reqLogger->info('Request received');
$reqLogger->error('Validation failed', ['field' => 'email']);
```

The parent client is **never modified**. Each child gets a fresh trace ID.

## Default context

```php
$client = new TinyOwl([
    'apiKey'         => $apiKey,
    'projectSecret'  => $secret,
    'defaultContext' => ['service' => 'billing', 'env' => 'prod'],
]);
$client->info('Invoice generated');
// Sent with context: {"service":"billing","env":"prod"}
```

Call-site context keys override `defaultContext` on conflict.

## Auto trace ID

By default each `TinyOwl` instance generates a UUID v4 on construction and attaches it as
`traceId` to every event. Child instances created with `withContext()` receive their own
fresh trace ID. Invalid trace IDs are dropped with a warning (`E_USER_WARNING`), never fatal.

Opt out:

```php
$client = new TinyOwl(['apiKey' => $apiKey, 'projectSecret' => $secret, 'autoTraceId' => false]);
```

## Error handling

```php
use TinyOwl\Observability\Exception\TinyOwlAuthException;
use TinyOwl\Observability\Exception\TinyOwlNetworkException;
use TinyOwl\Observability\Exception\TinyOwlTimeoutException;

try {
    $client->info('Hello');
} catch (TinyOwlAuthException $e) {
    echo "Auth failed ({$e->statusCode}): {$e->getMessage()}";
} catch (TinyOwlTimeoutException $e) {
    echo 'Request timed out';
} catch (TinyOwlNetworkException $e) {
    echo "Network error: {$e->getMessage()}";
}
```

## Introspection

```php
$config = $client->getConfig();
// [
//   'baseUrl'          => 'https://be.tiny-owl-kit.io/api',
//   'timeout'          => 5.0,
//   'autoTraceId'      => true,
//   'hasApiKey'        => true,
//   'hasProjectSecret' => true,
//   'instanceTraceId'  => '550e8400-...',
// ]
// Note: API key and project secret are never included.
```

## Security

Every request is signed with **HMAC-SHA256**:

- A cryptographically-random 32-hex-char nonce is generated per request (`random_bytes`).
- The current UTC timestamp (ISO-8601, milliseconds) is included to prevent replay attacks.
- Canonical JSON uses `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` so `/` and unicode
  match Node's `JSON.stringify` (required for HMAC parity).
- Empty `context` serializes as `{}`, not `[]`.
- The backend rejects requests outside a ±60-second window and rejects reused nonces.
- `projectSecret` is never logged or included in `getConfig()`.
- Plain-HTTP `baseUrl` over a non-localhost host emits a warning (OWASP A02).

## Requirements

- PHP 8.1+
- `ext-curl`, `ext-json` (no Composer runtime dependencies)

## License

MIT — see [LICENSE](LICENSE).

## Links

- [TinyOwl dashboard](https://tiny-owl-kit.io)
- [Changelog](CHANGELOG.md)
- [Issues](https://github.com/tiny-owl-kit/tiny-owl-php/issues)
