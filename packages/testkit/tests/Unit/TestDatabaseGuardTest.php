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

it('permits non-suffixed or remote databases when explicit destructive opt-in override is passed', function (): void {
    TestDatabaseGuard::assertSafe(
        new TestDatabaseIdentity('pgsql', 'ci-postgres.internal', 5432, 'custom_lane', 'ci'),
        optInOverride: true,
    );

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
