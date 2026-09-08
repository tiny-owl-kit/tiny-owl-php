# Changelog

All notable changes to `tiny-owl-kit/observability` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.2] — 2026-09-08

### Fixed

- PHPStan max: `getConfig()` no longer uses always-true empty-string checks for validated credentials.
- PHPStan max: `Transport::tryJson()` returns `array<string, mixed>|null` with string keys only.

## [0.1.1] — 2026-09-08

### Changed

- Patch release to re-trigger Packagist / release pipeline after initial `0.1.0` publish.

## [0.1.0] — 2026-09-07

### Added

- `TinyOwl` client with `info()`, `warning()`, `error()`, and `log()` methods.
- HMAC-SHA256 mandatory signing per the TinyOwl wire contract — full parity with the JavaScript SDK.
- `withContext()` for creating immutable child scopes with fresh trace IDs.
- `autoTraceId` (default `true`) — UUID v4 stable per instance, top-level `traceId` field.
- `defaultContext` merged into every event; call-site context wins on key conflicts.
- `getConfig()` introspection — never exposes secrets or API keys.
- `TinyOwlException` hierarchy: `TinyOwlAuthException`, `TinyOwlTimeoutException`,
  `TinyOwlNetworkException`, `TinyOwlValidationException`, `TinyOwlServerException`.
- Node-compatible canonical JSON: `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`,
  empty context as `{}`.
- Warning on non-HTTPS `baseUrl` over non-localhost (OWASP A02/A05).
- CI: PHPUnit on PHP 8.1–8.3, PHPStan max, php-cs-fixer, composer audit, gated E2E.
- Full test suite covering HMAC parity, JSON escaping, API, transport errors.

[Unreleased]: https://github.com/tiny-owl-kit/tiny-owl-php/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/tiny-owl-kit/tiny-owl-php/releases/tag/v0.1.2
[0.1.1]: https://github.com/tiny-owl-kit/tiny-owl-php/releases/tag/v0.1.1
[0.1.0]: https://github.com/tiny-owl-kit/tiny-owl-php/releases/tag/v0.1.0
