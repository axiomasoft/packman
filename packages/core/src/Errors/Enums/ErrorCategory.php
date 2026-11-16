<?php

declare(strict_types=1);

namespace Packman\Errors\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ErrorCategory: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Validation = 'validation';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case RateLimit = 'rate_limit';
    case Unsupported = 'unsupported';
    case Unavailable = 'unavailable';
    case Internal = 'internal';
}
