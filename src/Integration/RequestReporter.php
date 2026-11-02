<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Integration;

use Throwable;
use TinyOwl\Observability\Severity;
use TinyOwl\Observability\TinyOwl;

/**
 * Shared reporting rules for framework integrations.
 *
 * Only uncaught exceptions and responses with status >= 400 are sent.
 * Request bodies, query strings, headers and cookies are never included.
 */
final class RequestReporter
{
    public static function report(
        TinyOwl $client,
        string $method,
        string $endpoint,
        ?string $routeTemplate,
        int $statusCode,
        float $durationMs,
        ?string $errorClass = null,
    ): void {
        $uncaught = $errorClass !== null;
        if (!$uncaught && $statusCode < 400) {
            return;
        }

        $severity = $uncaught || $statusCode >= 500 ? Severity::Error : Severity::Warning;
        $message = $uncaught ? 'Unhandled exception: ' . $errorClass : sprintf('HTTP %d', $statusCode);

        $context = [
            'method' => $method,
            'endpoint' => $endpoint,
            'statusCode' => $statusCode,
            'durationMs' => round($durationMs, 2),
        ];
        if ($routeTemplate !== null) {
            $context['routeTemplate'] = $routeTemplate;
        }

        try {
            $client->log($message, $severity->value, $context);
        } catch (Throwable $e) {
            error_log('TinyOwl SDK: failed to report request: ' . $e->getMessage());
        }
    }
}
