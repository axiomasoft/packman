<?php

declare(strict_types=1);

use Packman\Testkit\Data\Database\TestDatabaseIdentity;
use Packman\Testkit\Exceptions\Database\UnsafeTestDatabaseException;
use Packman\Testkit\Support\Database\DatabaseLaneEnvironment;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;

it('allows *_test and *_testing databases on local hosts and sqlite memory', function (): void {
    TestDatabaseGuard::assertSafe(new TestDatabaseIdentity('pgsql', '127.0.0.1', 5432, 'packman_test', 'postgres'));
    TestDatabaseGuard::assertSafe(new TestDatabaseIdentity('pgsql', 'localhost', 5432, 'suite_testing', 'postgres'));
    TestDatabaseGuard::assertSafe(new TestDatabaseIdentity('sqlite', '', 0, ':memory:', ''));

    expect(true)->toBeTrue();
});

it('rejects reserved, empty, production-like, and un-opted remote databases', function (TestDatabaseIdentity $identity): void {
    expect(static fn () => TestDatabaseGuard::assertSafe($identity, optInOverride: false))
        ->toThrow(UnsafeTestDatabaseException::class);
})->with([
    'reserved postgres' => [new TestDatabaseIdentity('pgsql', '127.0.0.1', 5432, 'postgres', 'postgres')],
    'reserved prod' => [new TestDatabaseIdentity('pgsql', '127.0.0.1', 5432, 'prod', 'app')],
    'production suffix' => [new TestDatabaseIdentity('pgsql', '127.0.0.1', 5432, 'app_production', 'app')],
    'staging prefix' => [new TestDatabaseIdentity('pgsql', '127.0.0.1', 5432, 'staging_app_test', 'app')],
    'missing test suffix' => [new TestDatabaseIdentity('pgsql', '127.0.0.1', 5432, 'packman_dev', 'app')],
    'empty name' => [new TestDatabaseIdentity('pgsql', '127.0.0.1', 5432, '', 'app')],
    'remote host without opt-in' => [new TestDatabaseIdentity('pgsql', 'db.internal.example', 5432, 'packman_test', 'app')],
]);

it('rejects non-suffixed databases even when PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 or optInOverride is true, while permitting remote *_test databases with opt-in', function (): void {
    $previous = getenv('PACKMAN_TEST_DESTRUCTIVE_OPT_IN');
    $previousEnv = $_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] ?? null;
    $previousServer = $_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] ?? null;

    putenv('PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1');
    $_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] = '1';
    $_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] = '1';

    try {
        expect(static fn () => TestDatabaseGuard::assertSafe(
            new TestDatabaseIdentity('pgsql', '127.0.0.1', 5432, 'customer_data', 'app'),
        ))->toThrow(UnsafeTestDatabaseException::class, '_test');

        expect(static fn () => TestDatabaseGuard::assertSafe(
            new TestDatabaseIdentity('pgsql', 'ci-postgres.internal', 5432, 'custom_lane', 'ci'),
            optInOverride: true,
        ))->toThrow(UnsafeTestDatabaseException::class, '_test');

        expect(static fn () => TestDatabaseGuard::assertSafe(
            new TestDatabaseIdentity('sqlite', '', 0, '/var/data/customer_data.sqlite', ''),
            optInOverride: true,
        ))->toThrow(UnsafeTestDatabaseException::class, '_test');

        TestDatabaseGuard::assertSafe(
            new TestDatabaseIdentity('pgsql', 'ci-postgres.internal', 5432, 'ci_packman_test', 'ci'),
            optInOverride: true,
        );

        TestDatabaseGuard::assertDestructiveSafe(
            new TestDatabaseIdentity('pgsql', '127.0.0.1', 5432, 'packman_test', 'postgres'),
            optInOverride: true,
        );

        expect(static fn () => TestDatabaseGuard::assertDestructiveSafe(
            new TestDatabaseIdentity('pgsql', '127.0.0.1', 5432, 'packman_test', 'postgres'),
            optInOverride: false,
        ))->toThrow(UnsafeTestDatabaseException::class, 'PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1');

        TestDatabaseGuard::assertDestructiveSafe(
            new TestDatabaseIdentity('sqlite', '', 0, ':memory:', ''),
            optInOverride: false,
        );
    } finally {
        putenv($previous === false ? 'PACKMAN_TEST_DESTRUCTIVE_OPT_IN' : "PACKMAN_TEST_DESTRUCTIVE_OPT_IN={$previous}");

        if ($previousEnv === null) {
            unset($_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN']);
        } else {
            $_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] = $previousEnv;
        }

        if ($previousServer === null) {
            unset($_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN']);
        } else {
            $_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] = $previousServer;
        }
    }

    expect(SuiteDatabaseConnection::pgsqlDsn(new TestDatabaseIdentity('pgsql', '127.0.0.1', 54329, 'packman_test', 'skm')))
        ->toBe('pgsql:host=127.0.0.1;port=54329;dbname=packman_test');
});

it('computes worker lane database names when per-process isolation is active', function (): void {
    $previous = getenv('PACKMAN_TEST_DB_PER_PROCESS');
    $previousToken = getenv('TEST_TOKEN');

    putenv('PACKMAN_TEST_DB_PER_PROCESS=1');
    putenv('TEST_TOKEN=w3');
    $_ENV['PACKMAN_TEST_DB_PER_PROCESS'] = '1';
    $_ENV['TEST_TOKEN'] = 'w3';

    try {
        expect(DatabaseLaneEnvironment::perProcessEnabled())->toBeTrue()
            ->and(DatabaseLaneEnvironment::isolatedDatabaseName('packman_test'))->toBe('packman_w3_test');
    } finally {
        putenv($previous === false ? 'PACKMAN_TEST_DB_PER_PROCESS' : "PACKMAN_TEST_DB_PER_PROCESS={$previous}");
        putenv($previousToken === false ? 'TEST_TOKEN' : "TEST_TOKEN={$previousToken}");
        unset($_ENV['PACKMAN_TEST_DB_PER_PROCESS'], $_ENV['TEST_TOKEN']);
    }
});

it('does not treat sqlite :memory: DB_DATABASE as PGSQL_DATABASE in SuiteDatabaseConnection', function (): void {
    $keys = ['PGSQL_DATABASE', 'DB_CONNECTION', 'DB_DATABASE'];
    $saved = [];

    foreach ($keys as $key) {
        $saved[$key] = [
            'getenv' => getenv($key),
            'env' => $_ENV[$key] ?? null,
            'server' => $_SERVER[$key] ?? null,
        ];
    }

    putenv('PGSQL_DATABASE');
    unset($_ENV['PGSQL_DATABASE'], $_SERVER['PGSQL_DATABASE']);
    putenv('DB_CONNECTION=sqlite');
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
    putenv('DB_DATABASE=:memory:');
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['DB_DATABASE'] = ':memory:';

    try {
        $identity = SuiteDatabaseConnection::pgsqlIdentity();
        TestDatabaseGuard::assertSafe($identity);
        expect($identity->driver)->toBe('pgsql')
            ->and($identity->database)->toBe('packman_test');
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
});
