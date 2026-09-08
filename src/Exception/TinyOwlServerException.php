<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Exception;

/**
 * Raised on 5xx responses.
 */
class TinyOwlServerException extends TinyOwlException
{
    public function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message, $statusCode);
    }
}
