<?php

declare(strict_types=1);

namespace Packman\Testkit\Concurrency\Postgres;

use Illuminate\Support\Sleep;
use Packman\Attributes\Api;
use Packman\Testkit\Exceptions\Concurrency\BarrierTimeoutException;
use Packman\Testkit\Schema\Database\PdoQuery;
use PDO;

/**
 * Coordinates N PostgreSQL processes using a dedicated coordination table until all workers arrive.
 */
#[Api(since: '1.0')]
final readonly class PostgresBarrier
{
    private const string TABLE = 'packman_test_barriers';

    public function __construct(
        private PDO $pdo,
        private string $name,
        private int $participants,
    ) {}

    public function ensureTable(): void
    {
        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (barrier_name TEXT NOT NULL, worker_id TEXT NOT NULL, arrived_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), PRIMARY KEY (barrier_name, worker_id))',
            self::TABLE,
        ));
    }

    public function reset(): void
    {
        $this->ensureTable();
        $query = new PdoQuery($this->pdo);
        $query->execute(
            sprintf('DELETE FROM %s WHERE barrier_name = :name', self::TABLE),
            ['name' => $this->name],
        );
    }

    public function arriveAndWait(string $workerId, float $timeoutSeconds = 5.0): void
    {
        $this->ensureTable();
        $query = new PdoQuery($this->pdo);
        $query->execute(
            sprintf('INSERT INTO %s (barrier_name, worker_id) VALUES (:name, :worker) ON CONFLICT DO NOTHING', self::TABLE),
            ['name' => $this->name, 'worker' => $workerId],
        );

        $deadline = microtime(true) + max(0.05, $timeoutSeconds);
        $arrived = 0;

        while (microtime(true) < $deadline) {
            $raw = $query->scalar(
                sprintf('SELECT COUNT(*) FROM %s WHERE barrier_name = :name', self::TABLE),
                ['name' => $this->name],
            );
            $arrived = (is_int($raw) || is_string($raw)) ? (int) $raw : 0;

            if ($arrived >= $this->participants) {
                return;
            }

            Sleep::usleep(5_000);
        }

        throw BarrierTimeoutException::afterSeconds($this->name, $timeoutSeconds, $arrived, $this->participants);
    }
}
