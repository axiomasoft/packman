<?php

declare(strict_types=1);

namespace Packman\Modules\Enums\Lifecycle;

use Packman\Attributes\Api;

/**
 * Why a module was disabled (`mod_modules.disable_reason`).
 */
#[Api(since: '1.0')]
enum DisableReason: string
{
    case Manual = 'manual';
    case Billing = 'billing';
    case Dependency = 'dependency';
}
