<?php

declare(strict_types=1);

namespace Packman\Doctor\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
