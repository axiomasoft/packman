<?php

declare(strict_types=1);

namespace Packman\Testkit\Concerns\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packman\Attributes\Api;
use Packman\Testkit\Internal\ResolvedDatabaseConnection;

/** Drops all schema tables cleanly for zero-schema negative verification tests. */
#[Api(since: '1.0')]
trait WithoutDatabaseSchema
{
    protected function dropAllTablesSafely(string $connection = 'sqlite'): void
    {
        $resolved = ResolvedDatabaseConnection::assertDestructiveSafe($connection);

        if (strtolower(trim($resolved->getDriverName())) === 'pgsql') {
            $resolved->statement('DROP SCHEMA IF EXISTS public CASCADE');
            $resolved->statement('CREATE SCHEMA public');
            DB::purge($connection);

            return;
        }

        Schema::connection($connection)->dropAllTables();
    }
}
