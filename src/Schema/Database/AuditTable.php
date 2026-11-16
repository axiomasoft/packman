<?php

declare(strict_types=1);

namespace Packman\Testkit\Schema\Database;

use Packman\Attributes\Api;
use Packman\Testkit\Data\Database\AuditColumnSnapshot;
use PDO;
use RuntimeException;

/** Inspects table columns, indexes, and existence via PDO on PostgreSQL or SQLite. */
#[Api(since: '1.0')]
final readonly class AuditTable
{
    public function __construct(private PDO $pdo) {}

    public function driver(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return is_string($driver) ? strtolower($driver) : '';
    }

    public function exists(string $table, string $schema = 'public'): bool
    {
        $query = new PdoQuery($this->pdo);

        return match ($this->driver()) {
            'pgsql' => (bool) $query->scalar(
                'SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table)',
                ['schema' => $schema, 'table' => $table],
            ),
            'sqlite' => $query->scalar(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1",
                ['table' => $table],
            ) !== false,
            default => throw new RuntimeException("Unsupported audit driver [{$this->driver()}]."),
        };
    }

    /** @return list<AuditColumnSnapshot> */
    public function columns(string $table, string $schema = 'public'): array
    {
        $query = new PdoQuery($this->pdo);

        return match ($this->driver()) {
            'pgsql' => $this->mapPgsqlColumns($query->rows(
                'SELECT column_name, data_type, is_nullable, column_default
                 FROM information_schema.columns
                 WHERE table_schema = :schema AND table_name = :table
                 ORDER BY ordinal_position',
                ['schema' => $schema, 'table' => $table],
            )),
            'sqlite' => $this->mapSqliteColumns($query->rows(
                sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $table)),
            )),
            default => throw new RuntimeException("Unsupported audit driver [{$this->driver()}]."),
        };
    }

    /** @return list<string> */
    public function columnNames(string $table, string $schema = 'public'): array
    {
        return array_map(
            static fn (AuditColumnSnapshot $column): string => $column->name,
            $this->columns($table, $schema),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<AuditColumnSnapshot>
     */
    private function mapPgsqlColumns(array $rows): array
    {
        $snapshots = [];
        foreach ($rows as $row) {
            $name = is_string($row['column_name'] ?? null) ? $row['column_name'] : '';
            $type = is_string($row['data_type'] ?? null) ? $row['data_type'] : '';
            $nullable = strtoupper(is_string($row['is_nullable'] ?? null) ? $row['is_nullable'] : '') === 'YES';
            $default = is_string($row['column_default'] ?? null) ? $row['column_default'] : null;

            $snapshots[] = new AuditColumnSnapshot($name, $type, $nullable, $default);
        }

        return $snapshots;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<AuditColumnSnapshot>
     */
    private function mapSqliteColumns(array $rows): array
    {
        $snapshots = [];
        foreach ($rows as $row) {
            $name = is_string($row['name'] ?? null) ? $row['name'] : '';
            $type = is_string($row['type'] ?? null) ? $row['type'] : '';
            $rawNotNull = $row['notnull'] ?? 0;
            $nullable = (is_int($rawNotNull) || is_string($rawNotNull)) && ((int) $rawNotNull) === 0;
            $rawDefault = $row['dflt_value'] ?? null;
            $default = is_string($rawDefault) ? $rawDefault : null;

            $snapshots[] = new AuditColumnSnapshot($name, $type, $nullable, $default);
        }

        return $snapshots;
    }
}
