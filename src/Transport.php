<?php

declare(strict_types=1);

namespace TinyOwl\Observability;

use TinyOwl\Observability\Exception\TinyOwlAuthException;
use TinyOwl\Observability\Exception\TinyOwlException;
use TinyOwl\Observability\Exception\TinyOwlNetworkException;
use TinyOwl\Observability\Exception\TinyOwlServerException;
use TinyOwl\Observability\Exception\TinyOwlTimeoutException;

/**
 * Low-level HTTP transport to the TinyOwl ingest endpoint (cURL, no extra deps).
 */
final class Transport
{
    /** @var list<string> */
    private const LOCALHOST_HOSTS = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /**
     * Test-only HTTP handler. When set, sendEvent() uses it instead of cURL.
     *
     * Signature: `fn(string $url, string $body, array $headers, float $timeout): array{status:int, body:string, errno?:int, error?:string}`
     *
     * @var (callable(string, string, array<string, string>, float): array{status: int, body: string, errno?: int, error?: string})|null
     *
     * @internal
     */
    public static $testHandler = null;

    /**
     * Warn if $baseUrl uses plain HTTP over a non-localhost host (OWASP A02).
     */
    public static function warnIfInsecure(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if ($scheme === 'http' && !in_array(strtolower($host), self::LOCALHOST_HOSTS, true)) {
            trigger_error(
                'TinyOwl SDK: baseUrl uses plain HTTP over a non-localhost host. '
                . 'Use HTTPS in production to protect your credentials (OWASP A02).',
                E_USER_WARNING,
            );
        }
    }

    /**
     * POST $payload to `{baseUrl}/ingest` and return the parsed JSON response.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public static function sendEvent(string $baseUrl, array $payload, array $headers, float $timeout): array
    {
        $url = rtrim($baseUrl, '/') . '/ingest';
        $body = Security::canonicalJson($payload);

        if (self::$testHandler !== null) {
            $result = (self::$testHandler)($url, $body, $headers, $timeout);
            $status = $result['status'];
            $text = $result['body'];
            $errno = $result['errno'] ?? 0;
            $error = $result['error'] ?? '';

            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TinyOwlTimeoutException("Request timed out after {$timeout}s");
            }
            if ($errno !== 0) {
                throw new TinyOwlNetworkException("Connection failed: {$error}");
            }
        } else {
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            $ch = curl_init($url);
            if ($ch === false) {
                throw new TinyOwlNetworkException('Failed to initialise cURL');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int) round($timeout * 1000),
            ]);

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TinyOwlTimeoutException("Request timed out after {$timeout}s");
            }

            if ($raw === false || $errno !== 0) {
                throw new TinyOwlNetworkException("Connection failed: {$error}");
            }

            $text = is_string($raw) ? $raw : '';
        }

        $decoded = self::tryJson($text);

        if ($status === 401 || $status === 403) {
            $msg = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])
                ? $decoded['message']
                : $text;
            throw new TinyOwlAuthException($msg, $status);
        }

        if ($status >= 500) {
            throw new TinyOwlServerException(
                "Server error {$status}: " . substr($text, 0, 200),
                $status,
            );
        }

        if ($status < 200 || $status >= 300) {
            $msg = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])
                ? $decoded['message']
                : $text;
            throw new TinyOwlException("HTTP {$status}: {$msg}");
        }

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function tryJson(string $text): ?array
    {
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
