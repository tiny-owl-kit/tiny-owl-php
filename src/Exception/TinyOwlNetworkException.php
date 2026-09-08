<?php

declare(strict_types=1);

namespace TinyOwl\Observability\Exception;

use Throwable;

/**
 * Raised for connection-level failures (DNS, refused, etc.).
 */
class TinyOwlNetworkException extends TinyOwlException
{
    public function __construct(string $message, public readonly ?Throwable $cause = null)
    {
        parent::__construct($message, 0, $cause);
    }
}
