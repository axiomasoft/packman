<?php

declare(strict_types=1);

namespace Packman\Testkit\Concerns\Database;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Packman\Attributes\Api;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;

/** Wires guarded PGSQL suite connection settings into a Laravel application config. */
#[Api(since: '1.0')]
trait UsesSuiteDatabase
{
    protected function configureSuitePgsqlConnection(ConfigRepository $config, string $connection = 'pgsql'): void
    {
        $identity = SuiteDatabaseConnection::pgsqlIdentity();
        TestDatabaseGuard::assertSafe($identity);
        $config->set("database.connections.{$connection}", SuiteDatabaseConnection::laravelPgsqlConfig($identity));
    }
}
