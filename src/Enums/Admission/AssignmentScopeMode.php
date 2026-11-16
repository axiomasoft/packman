<?php

declare(strict_types=1);

namespace Packman\Panels\Enums\Admission;

use Packman\Attributes\Api;

/**
 * How a panel treats assignment scopes.
 */
#[Api(since: '1.0')]
enum AssignmentScopeMode: string
{
    case None = 'none';
    case Inherit = 'inherit';
    case Isolated = 'isolated';
    case Required = 'required';
}
