<?php

declare(strict_types=1);

namespace Packman\Panels\Enums\Panel;

use Packman\Attributes\Api;

/**
 * How often a panel re-reads the state version that invalidates cached decisions.
 */
#[Api(since: '1.0')]
enum StateRefresh: string
{
    case Request = 'request';
    case Check = 'check';
}
