<?php

declare(strict_types=1);

namespace Packman\Testkit\Concurrency\Postgres;

use Packman\Attributes\Api;
use Packman\Testkit\Schema\Database\PdoQuery;
use PDO;
use Throwable;

/** Helper running PostgreSQL transaction-scoped advisory lock probes under transaction pooling. */
#[Api(since: '1.0')]
final readonly class AdvisoryLockRace
{
    public function __construct(private PDO $pdo) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return array{acquired: bool, value: TReturn|null}
     */
    public function withTransactionLock(int $lockId, callable $callback): array
    {
        $this->pdo->beginTransaction();

        try {
            $result = new PdoQuery($this->pdo)->scalar('SELECT pg_try_advisory_xact_lock(:id)', ['id' => $lockId]);
            $acquired = filter_var($result, FILTER_VALIDATE_BOOLEAN);

            if (! $acquired) {
                $this->pdo->rollBack();

                return ['acquired' => false, 'value' => null];
            }

            $value = $callback();
            $this->pdo->commit();

            return ['acquired' => true, 'value' => $value];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
