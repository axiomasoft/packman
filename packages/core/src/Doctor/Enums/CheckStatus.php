<?php

declare(strict_types=1);

namespace Packman\Doctor\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum CheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Skipped = 'skipped';
}
