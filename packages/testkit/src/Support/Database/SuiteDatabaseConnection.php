<?php

declare(strict_types=1);

namespace Packman\Testkit\Support\Database;

use Packman\Attributes\Api;
use Packman\Testkit\Data\Database\TestDatabaseIdentity;
use Packman\Testkit\Internal\EnvLookup;
use PDO;
use Throwable;

/** Resolves PostgreSQL connection identities and PDO handles from PGSQL_* / DB_* env, with libpq fallbacks and PACKMAN_PGSQL_CONNECT_TIMEOUT. */
#[Api(since: '1.0')]
final class SuiteDatabaseConnection
{
    public static function pgsqlIdentity(): TestDatabaseIdentity
    {
        $host = EnvLookup::string('PGSQL_HOST', 'DB_HOST', 'PGHOST') ?? '127.0.0.1';
        $port = (int) (EnvLookup::string('PGSQL_PORT', 'DB_PORT', 'PGPORT') ?? '5432');
        $databaseName = EnvLookup::string('PGSQL_DATABASE', 'PGDATABASE');

        if ($databaseName === null) {
            $dbConnection = EnvLookup::string('DB_CONNECTION');
            $fallbackDatabase = ($dbConnection === null || strtolower($dbConnection) === 'pgsql')
                ? EnvLookup::string('DB_DATABASE')
                : null;
            $databaseName = ($fallbackDatabase !== null && $fallbackDatabase !== ':memory:')
                ? $fallbackDatabase
                : 'packman_test';
        }

        $database = DatabaseLaneEnvironment::isolatedDatabaseName($databaseName);
        $username = EnvLookup::string('PGSQL_USERNAME', 'DB_USERNAME', 'PGUSER') ?? 'postgres';
        $schema = EnvLookup::string('PGSQL_SCHEMA') ?? 'public';

        return new TestDatabaseIdentity(
            driver: 'pgsql',
            host: $host,
            port: $port,
            database: $database,
            username: $username,
            schema: $schema,
        );
    }

    public static function pgsqlPassword(): string
    {
        return EnvLookup::string('PGSQL_PASSWORD', 'DB_PASSWORD', 'PGPASSWORD') ?? '';
    }

    public static function pgsqlDsn(?TestDatabaseIdentity $identity = null): string
    {
        $target = $identity ?? self::pgsqlIdentity();

        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $target->host,
            $target->port,
            $target->database,
        );
    }

    /** @return array<string, mixed> */
    public static function laravelPgsqlConfig(?TestDatabaseIdentity $identity = null): array
    {
        $target = $identity ?? self::pgsqlIdentity();

        return [
            'driver' => 'pgsql',
            'host' => $target->host,
            'port' => $target->port,
            'database' => $target->database,
            'username' => $target->username,
            'password' => self::pgsqlPassword(),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => $target->schema ?? 'public',
            'sslmode' => 'prefer',
        ];
    }

    public static function connectPgsql(?TestDatabaseIdentity $identity = null): PDO
    {
        $target = $identity ?? self::pgsqlIdentity();
        TestDatabaseGuard::assertSafe($target);
        $timeout = self::pgsqlConnectTimeout();

        return new PDO(
            sprintf('%s;connect_timeout=%d', self::pgsqlDsn($target), $timeout),
            $target->username,
            self::pgsqlPassword(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => $timeout,
            ],
        );
    }

    public static function canConnectPgsql(): bool
    {
        try {
            self::connectPgsql();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function pgsqlConnectTimeout(): int
    {
        $raw = EnvLookup::string('PACKMAN_PGSQL_CONNECT_TIMEOUT');

        if ($raw === null || ! ctype_digit($raw)) {
            return 3;
        }

        $timeout = (int) $raw;

        if ($timeout < 1) {
            return 3;
        }

        return $timeout > 120 ? 120 : $timeout;
    }
}
