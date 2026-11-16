<?php

declare(strict_types=1);

namespace Packman\Panels\Enums\Admission;

use Packman\Attributes\Api;

/**
 * Whether a panel isolates tenants.
 */
#[Api(since: '1.0')]
enum TenantMode: string
{
    case None = 'none';
    case Required = 'required';
}
