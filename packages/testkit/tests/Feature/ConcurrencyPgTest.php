<?php

declare(strict_types=1);

use Packman\Testkit\Concurrency\Postgres\AdvisoryLockRace;
use Packman\Testkit\Concurrency\Postgres\PostgresBarrier;
use Packman\Testkit\Concurrency\Process\ForkRace;
use Packman\Testkit\Schema\Database\AuditTable;
use Packman\Testkit\Schema\Database\PdoQuery;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Runtime\TestGroups;

uses()->group('pg');

it('coordinates multiple child processes over PostgreSQL barriers and advisory locks', function (): void {
    if (! TestGroups::hasPcntl() || ! TestGroups::hasPgsql()) {
        $this->markTestSkipped('ext-pcntl and reachable PostgreSQL test database are required.');
    }

    $barrierName = 'race-'.bin2hex(random_bytes(6));
    $lockId = random_int(100_000, 999_999);
    $workers = 3;

    $setupPdo = SuiteDatabaseConnection::connectPgsql();
    $barrier = new PostgresBarrier($setupPdo, $barrierName, $workers);
    $barrier->reset();
    $audit = new AuditTable($setupPdo);
    expect($audit->exists('packman_test_barriers'))->toBeTrue()
        ->and($audit->columnNames('packman_test_barriers'))->toContain('barrier_name', 'worker_id');
    unset($setupPdo);

    $race = ForkRace::run($workers, static function (int $workerIndex) use ($barrierName, $lockId, $workers): bool {
        $pdo = SuiteDatabaseConnection::connectPgsql();
        $localBarrier = new PostgresBarrier($pdo, $barrierName, $workers);
        $localBarrier->arriveAndWait("worker-{$workerIndex}", timeoutSeconds: 5.0);

        $locks = new AdvisoryLockRace($pdo);
        $outcome = $locks->withTransactionLock($lockId, static function (): bool {
            usleep(40_000);

            return true;
        });

        return $outcome['acquired'];
    });

    $race->assertAllSucceeded();
    $winners = array_filter($race->values(), static fn (mixed $v): bool => $v === true);
    expect($winners)->not->toBeEmpty()
        ->and(count($winners))->toBeLessThanOrEqual($workers);

    $cleanupPdo = SuiteDatabaseConnection::connectPgsql();
    (new PdoQuery($cleanupPdo))->execute(
        'DELETE FROM packman_test_barriers WHERE barrier_name = :name',
        ['name' => $barrierName],
    );
});
