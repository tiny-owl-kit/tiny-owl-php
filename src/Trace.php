<?php

declare(strict_types=1);

namespace TinyOwl\Observability;

/**
 * Trace ID generation and validation.
 */
final class Trace
{
    public const PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';

    /**
     * Generate a UUID v4 trace ID.
     */
    public static function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * Return true if $traceId matches the allowed pattern.
     */
    public static function isValid(string $traceId): bool
    {
        return preg_match(self::PATTERN, $traceId) === 1;
    }
}
