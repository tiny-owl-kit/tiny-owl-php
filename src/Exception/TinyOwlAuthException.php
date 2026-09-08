<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Exception;

/**
 * Raised on 401/403 responses — invalid API key, signature mismatch, etc.
 */
class TinyOwlAuthException extends TinyOwlException
{
    public function __construct(string $message, public readonly int $statusCode = 401)
    {
        parent::__construct($message, $statusCode);
    }
}
