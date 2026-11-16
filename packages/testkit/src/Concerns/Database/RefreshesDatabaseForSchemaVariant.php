<?php

declare(strict_types=1);

namespace Packman\Testkit\Concerns\Database;

use Illuminate\Support\Facades\DB;
use Packman\Attributes\Api;
use Packman\Testkit\Data\Database\TestDatabaseIdentity;
use Packman\Testkit\Internal\ResolvedDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;

/** Refreshes PostgreSQL or SQLite test schemas cleanly before schema-variant tests. */
#[Api(since: '1.0')]
trait RefreshesDatabaseForSchemaVariant
{
    protected function resetPgsqlPublicSchema(string $connection = 'pgsql'): void
    {
        $resolved = ResolvedDatabaseConnection::assertDestructiveSafe($connection, requiredDriver: 'pgsql');

        $resolved->statement('DROP SCHEMA IF EXISTS public CASCADE');
        $resolved->statement('CREATE SCHEMA public');
        DB::purge($connection);
    }

    protected function assertConnectionGuarded(string $driver, string $database, string $host = '127.0.0.1'): void
    {
        TestDatabaseGuard::assertSafe(new TestDatabaseIdentity(
            driver: $driver,
            host: $host,
            port: $driver === 'pgsql' ? 5432 : 0,
            database: $database,
            username: 'test',
        ));
    }
}
