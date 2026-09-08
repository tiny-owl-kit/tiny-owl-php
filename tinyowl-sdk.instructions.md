# TinyOwl PHP SDK — AI Agent Instructions

This file provides guidance for AI coding assistants (GitHub Copilot, etc.)
working inside the `tiny-owl-php` repository.

---

## Project purpose

`tiny-owl-kit/observability` is the official PHP SDK for the [TinyOwl](https://tiny-owl-kit.io)
observability platform. It mirrors the public API and wire contract of the reference
JavaScript SDK (`@tiny-owl-kit/observability` / `tiny-owl-js`).

---

## Key design constraints — never violate these

1. **Wire parity with the JS SDK is non-negotiable.**
   - The signed payload key order is fixed: `message → severity → context → [traceId] → timestamp → nonce`.
   - `Security::canonicalJson()` uses `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
   - Empty associative arrays MUST serialize as `{}`, not `[]`.
   - The HMAC parity test vector in `tests/SecurityTest.php` (`PARITY_SIGNATURE`) MUST always pass.

2. **`traceId` is a top-level wire field — never nested inside `context`.**

3. **Secrets are never logged, never returned by `getConfig()`.**
   `getConfig()` returns `hasApiKey` and `hasProjectSecret` booleans only.

4. **HMAC signing is mandatory.** There is no "disable signing" path.

5. **`withContext()` returns a new immutable instance.** Parent is never modified.

6. **PHP 8.1+.** Enums, readonly properties, constructor promotion are in use.
   Invalid `traceId` is dropped with `trigger_error(E_USER_WARNING)`, never fatal.

7. **Zero runtime Composer deps.** HTTP is cURL (`ext-curl`). Crypto is `hash_hmac` / `random_bytes`.

---

## Project layout

```
src/
  TinyOwl.php            — main client: log/info/warning/error/withContext/getConfig
  Security.php           — HMAC signing, nonce, canonical JSON, timestamp
  Transport.php          — HTTP send via cURL, error mapping
  Trace.php              — UUID v4 generation, TRACE_ID_RE validation
  Severity.php           — enum Info/Warning/Error
  Exception/             — TinyOwlException hierarchy
tests/
  SecurityTest.php       — HMAC parity vector + canonical JSON edge cases
  ClientTest.php         — API, context precedence, traceId, withContext
  TransportTest.php      — timeout, non-2xx errors, connection failures
  e2e/SmokeTest.php      — gated live ingest (skipped without env secrets)
examples/
  basic.php
  laravel-symfony.php
```

---

## Canonical HMAC parity test vector

**Fixed inputs** (must never change):

| Field     | Value                                    |
| --------- | ---------------------------------------- |
| message   | `Payment processed`                      |
| severity  | `info`                                   |
| context   | `{"order_id": "ORD-9", "amount": 49.99}` |
| traceId   | `req-abc-123`                            |
| timestamp | `2026-08-19T10:20:30.000Z`               |
| nonce     | `a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4`       |
| secret    | `super-secret-project-key-for-testing`   |

**Expected signature**: `cee771d25dcfb623fcefe37860dbac955fa10bf717e1de789cbbf2865a59b3e8`

Verified against Node.js `crypto.createHmac`.

---

## Coding conventions

- **English only** — all code, comments, docs, commits.
- **No secrets in code** — read from environment variables only.
- **PHPStan level max** — all public methods fully typed.
- **php-cs-fixer** with PSR-12 + `declare(strict_types=1)`.
- Run `composer test` and PHPStan before every commit.

---

## Running tests

```bash
composer install
composer test            # PHPUnit (unit suite; e2e skipped without secrets)
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
```

---

## End-to-end smoke test

Requires a running backend (local or production) and a real project:

```bash
export TINYOWL_API_KEY="<your project api key>"
export TINYOWL_PROJECT_SECRET="<your project secret>"
# optional: export TINYOWL_BASE_URL="http://localhost:5001/api"
php examples/basic.php
# Look for hmacVerified: true in the response
```

---

## Release process

Version source of truth is the `VERSION` file. Bump `VERSION` + `CHANGELOG.md` on
`main` → `release.yml` creates tag `v<version>` and a GitHub Release. Packagist
publishes automatically from the tag via its GitHub webhook (no token in the repo).
Unchanged version → no tag → no publish.

```bash
composer require tiny-owl-kit/observability
```
