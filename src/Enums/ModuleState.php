<?php

declare(strict_types=1);

namespace Packman\Modules\Enums;

use Packman\Attributes\Api;

/**
 * Per-account module state machine. `Available` is virtual — code is
 * deployed but `mod_modules` has no row for the module yet.
 */
#[Api(since: '1.0')]
enum ModuleState: string
{
    case Available = 'available';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Archived = 'archived';
    case Purged = 'purged';
}
