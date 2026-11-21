<?php

declare(strict_types=1);

use Packman\Testkit\Data\Database\TestDatabaseIdentity;
use Packman\Testkit\Support\Database\DatabaseLaneEnvironment;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;

/**
 * @param  array<string, string|null>  $overrides
 * @param  callable(): mixed  $callback
 */
function isolateSuiteDatabaseEnv(array $overrides, callable $callback): mixed
{
    $saved = [];

    foreach (array_keys($overrides) as $key) {
        $saved[$key] = [
            'getenv' => getenv($key),
            'env' => $_ENV[$key] ?? null,
            'server' => $_SERVER[$key] ?? null,
        ];
    }

    try {
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return $callback();
    } finally {
        foreach ($saved as $key => $prev) {
            putenv($prev['getenv'] === false ? $key : "{$key}={$prev['getenv']}");

            if ($prev['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $prev['env'];
            }

            if ($prev['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $prev['server'];
            }
        }
    }
}

it('reads libpq variables when PGSQL_* and DB host credentials are absent', function (): void {
    isolateSuiteDatabaseEnv([
        'PGSQL_HOST' => null,
        'PGSQL_PORT' => null,
        'PGSQL_DATABASE' => null,
        'PGSQL_USERNAME' => null,
        'PGSQL_PASSWORD' => null,
        'PGSQL_SCHEMA' => null,
        'DB_HOST' => null,
        'DB_PORT' => null,
        'DB_USERNAME' => null,
        'DB_PASSWORD' => null,
        'PACKMAN_TEST_DB_PER_PROCESS' => null,
        'PGHOST' => 'db.internal',
        'PGPORT' => '5433',
        'PGDATABASE' => 'packman_ci_test',
        'PGUSER' => 'ci',
        'PGPASSWORD' => 'secret',
    ], static function (): void {
        $identity = SuiteDatabaseConnection::pgsqlIdentity();

        expect($identity->driver)->toBe('pgsql')
            ->and($identity->host)->toBe('db.internal')
            ->and($identity->port)->toBe(5433)
            ->and($identity->database)->toBe('packman_ci_test')
            ->and($identity->database)->toBe(DatabaseLaneEnvironment::isolatedDatabaseName('packman_ci_test'))
            ->and($identity->username)->toBe('ci')
            ->and($identity->schema)->toBe('public')
            ->and(SuiteDatabaseConnection::pgsqlPassword())->toBe('secret');
    });
});

it('prefers PGSQL_HOST over the libpq PGHOST fallback', function (): void {
    isolateSuiteDatabaseEnv([
        'PGSQL_HOST' => '10.0.0.8',
        'PGHOST' => 'db.internal',
    ], static function (): void {
        expect(SuiteDatabaseConnection::pgsqlIdentity()->host)->toBe('10.0.0.8');
    });
});

it('keeps connect_timeout out of an explicit pgsql DSN', function (): void {
    isolateSuiteDatabaseEnv([
        'PACKMAN_PGSQL_CONNECT_TIMEOUT' => '15',
    ], static function (): void {
        $dsn = SuiteDatabaseConnection::pgsqlDsn(new TestDatabaseIdentity('pgsql', '127.0.0.1', 54329, 'packman_test', 'skm'));

        expect($dsn)->toBe('pgsql:host=127.0.0.1;port=54329;dbname=packman_test')
            ->and($dsn)->not->toContain('connect_timeout');
    });
});

it('bounds the private PostgreSQL connect timeout', function (): void {
    $method = new ReflectionMethod(SuiteDatabaseConnection::class, 'pgsqlConnectTimeout');
    $timeout = static fn (): int => $method->invoke(null);

    expect(isolateSuiteDatabaseEnv(['PACKMAN_PGSQL_CONNECT_TIMEOUT' => null], $timeout))->toBe(3)
        ->and(isolateSuiteDatabaseEnv(['PACKMAN_PGSQL_CONNECT_TIMEOUT' => '15'], $timeout))->toBe(15)
        ->and(isolateSuiteDatabaseEnv(['PACKMAN_PGSQL_CONNECT_TIMEOUT' => '0'], $timeout))->toBe(3)
        ->and(isolateSuiteDatabaseEnv(['PACKMAN_PGSQL_CONNECT_TIMEOUT' => '-1'], $timeout))->toBe(3)
        ->and(isolateSuiteDatabaseEnv(['PACKMAN_PGSQL_CONNECT_TIMEOUT' => 'abc'], $timeout))->toBe(3)
        ->and(isolateSuiteDatabaseEnv(['PACKMAN_PGSQL_CONNECT_TIMEOUT' => '500'], $timeout))->toBe(120);
});
