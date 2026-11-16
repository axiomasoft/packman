<?php

declare(strict_types=1);

namespace Packman\Testkit\Schema\Database;

use Packman\Attributes\Api;
use PDO;

/** Minimal prepared-statement helper wrapping raw PDO connections for standalone test harnesses. */
#[Api(since: '1.0')]
final readonly class PdoQuery
{
    public function __construct(private PDO $pdo) {}

    /** @param array<int|string, mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    /** @param array<int|string, mixed> $bindings */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchColumn();
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        /** @var list<array<string, mixed>> $fetched */
        $fetched = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $fetched;
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return list<mixed>
     */
    public function column(string $sql, array $bindings = [], int $columnIndex = 0): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN, $columnIndex));
    }
}
