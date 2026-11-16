<?php

declare(strict_types=1);

namespace Packman\Panels\Enums\Panel;

use Packman\Attributes\Api;

/**
 * Which database connection decisions read grants from.
 */
#[Api(since: '1.0')]
enum Reads: string
{
    /** The write PDO outside an unrecognized transaction; decisions do not see replica lag. */
    case Primary = 'primary';

    /** The configured read PDO, permitting replica delay. */
    case Default = 'default';
}
