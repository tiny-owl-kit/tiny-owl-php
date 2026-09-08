<?php

declare(strict_types=1);

namespace TinyOwl\Observability;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
