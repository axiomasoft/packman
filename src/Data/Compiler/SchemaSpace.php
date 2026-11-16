<?php

declare(strict_types=1);

namespace Packman\Schema\Data\Compiler;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Enums\IdStrategy;

/**
 * The storage decisions of one space that shape its schema — and nothing else.
 * Physical table of a logical name: tables[logical] ?? "{tablePrefix}_{logical}".
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class SchemaSpace
{
    /**
     * @param  array<string, string>  $tables  explicit logical => physical overrides
     * @param  array<string, bool>|null  $features  space feature flags by key; null = every feature on
     */
    public function __construct(
        public string $id,
        public bool $isDefault,
        public string $connection,
        public string $connectionPrefix,
        public IdStrategy $idStrategy,
        public string $tablePrefix,
        public array $tables = [],
        public ?array $features = null,
    ) {}

    public function feature(string $key): bool
    {
        return $this->features === null || ($this->features[$key] ?? false);
    }

    /** Physical name as the application sees it (Laravel adds the connection prefix). */
    public function table(string $logical): string
    {
        return $this->tables[$logical] ?? $this->tablePrefix.'_'.$logical;
    }

    /** Physical name as the database sees it. */
    public function databaseTable(string $logical): string
    {
        return $this->connectionPrefix.$this->table($logical);
    }

    /**
     * The space_state row (without timestamps).
     *
     * @return array{space: string, id_strategy: string, table_prefix: string, table_map: string|null, fingerprint: string}
     */
    public function stateRow(): array
    {
        $tables = $this->tables;
        ksort($tables);

        return [
            'space' => $this->id,
            'id_strategy' => $this->idStrategy->value,
            'table_prefix' => $this->tablePrefix,
            'table_map' => $tables === [] ? null : json_encode($tables, JSON_THROW_ON_ERROR),
            'fingerprint' => $this->fingerprint(),
        ];
    }

    /**
     * sha256 of the decisions that must never change after the first migrate: id strategy, prefix and overrides.
     */
    public function fingerprint(): string
    {
        $tables = $this->tables;
        ksort($tables);

        return hash('sha256', json_encode([
            'id_strategy' => $this->idStrategy->value,
            'table_prefix' => $this->tablePrefix,
            'tables' => $tables,
        ], JSON_THROW_ON_ERROR));
    }
}
