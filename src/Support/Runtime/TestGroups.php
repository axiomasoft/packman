<?php

declare(strict_types=1);

namespace Packman\Testkit\Support\Runtime;

use Packman\Attributes\Api;
use Packman\Testkit\Internal\EnvLookup;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;

/** Runtime capability probes for PGSQL, pcntl, and CI test groups. */
#[Api(since: '1.0')]
final class TestGroups
{
    public static function hasPcntl(): bool
    {
        return extension_loaded('pcntl') && function_exists('pcntl_fork');
    }

    public static function hasPgsql(): bool
    {
        return extension_loaded('pdo_pgsql') && SuiteDatabaseConnection::canConnectPgsql();
    }

    public static function isCi(): bool
    {
        return EnvLookup::bool('CI');
    }
}
