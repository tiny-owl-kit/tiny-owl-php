<?php

declare(strict_types=1);

namespace TinyOwl\Observability;

use DateTimeImmutable;
use DateTimeZone;

/**
 * HMAC-SHA256 signing, nonce generation, and canonical JSON serialization.
 *
 * Canonical JSON MUST round-trip through Node's JSON.stringify:
 * compact, insertion-order preserved, no slash/unicode escaping.
 */
final class Security
{
    /**
     * Test-only timestamp override used by createSecureHeaders().
     *
     * @internal
     */
    public static ?string $testTimestamp = null;

    /**
     * Test-only nonce override used by createSecureHeaders().
     *
     * @internal
     */
    public static ?string $testNonce = null;

    /**
     * Return 32 cryptographically-random hex characters (16 bytes).
     */
    public static function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Return an ISO-8601 timestamp that matches Node's `new Date().toISOString()`.
     *
     * Format: `YYYY-MM-DDTHH:MM:SS.mmmZ` (exactly 3 ms digits, Z suffix).
     */
    public static function jsIsoTimestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Serialize $value to compact JSON that round-trips through Node's JSON.stringify.
     *
     * Empty associative arrays are emitted as `{}`, not `[]`.
     *
     * @param mixed $value
     */
    public static function canonicalJson(mixed $value): string
    {
        $encoded = json_encode(
            self::prepareForJson($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return $encoded;
    }

    /**
     * Build the ordered array that is HMAC-signed.
     *
     * Key order is fixed: message → severity → context → [traceId] → timestamp → nonce.
     * This must exactly match the order used by the backend and JS SDK.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function buildSignablePayload(
        string $message,
        string $severity,
        array $context,
        string $timestamp,
        string $nonce,
        ?string $traceId = null,
    ): array {
        $payload = [
            'message' => $message,
            'severity' => $severity,
            'context' => $context,
        ];
        if ($traceId !== null) {
            $payload['traceId'] = $traceId;
        }
        $payload['timestamp'] = $timestamp;
        $payload['nonce'] = $nonce;

        return $payload;
    }

    /**
     * HMAC-SHA256 over the canonical JSON of $payload; returns lowercase hex.
     *
     * @param array<string, mixed> $payload
     */
    public static function signPayload(array $payload, string $projectSecret): string
    {
        return hash_hmac('sha256', self::canonicalJson($payload), $projectSecret);
    }

    /**
     * Return the three HMAC security headers required by the backend.
     *
     * @param array<string, mixed> $context
     *
     * @return array{x-signature: string, x-timestamp: string, x-nonce: string}
     */
    public static function createSecureHeaders(
        string $message,
        string $severity,
        array $context,
        string $projectSecret,
        ?string $traceId = null,
    ): array {
        $timestamp = self::$testTimestamp ?? self::jsIsoTimestamp();
        $nonce = self::$testNonce ?? self::generateNonce();
        $signable = self::buildSignablePayload(
            $message,
            $severity,
            $context,
            $timestamp,
            $nonce,
            $traceId,
        );
        $signature = self::signPayload($signable, $projectSecret);

        return [
            'x-signature' => $signature,
            'x-timestamp' => $timestamp,
            'x-nonce' => $nonce,
        ];
    }

    /**
     * Recursively convert empty arrays to objects so they serialize as `{}`.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function prepareForJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return (object) [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::prepareForJson($item);
        }

        return $out;
    }
}
