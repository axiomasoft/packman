<?php

declare(strict_types=1);

namespace Packman\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use LogicException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Data\Compiler\SchemaSpace;
use Packman\Schema\Enums\IdStrategy;
use Packman\Schema\Internal\Contracts\SchemaWriter;
use Packman\Schema\Internal\Data\SchemaObject;
use Packman\Schema\Internal\Services\SchemaNames;
use Packman\Schema\Internal\Services\Values;

/**
 * Everything a schema step may use: the space's naming, key shapes and a DDL sink. The column shapes produced here are
 * frozen for 1.x — released migrations call these methods, so their output never changes; a new shape is a new method.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class SpaceSchema
{
    public const string FUNCTION = 'function';

    public const string TRIGGER = 'trigger';

    /** @var array<string, true> */
    private array $named = [];

    /** @var list<SchemaObject> */
    private array $pending = [];

    /**
     * Built only by SpaceSchemaFactory: a step receives it and never constructs it.
     *
     * @param  list<string>  $owned  logical tables of the running component
     * @param  list<string>  $referenceable  owned + tables of the component's dependencies
     * @param  array<string, string>  $owners  logical table => id of the component that declares it
     * @param  string  $step  id of the running step, for diagnostics
     */
    private function __construct(
        private readonly SchemaSpace $space,
        private readonly string $component,
        private readonly array $owned,
        private readonly array $referenceable,
        private readonly SchemaWriter $writer,
        private readonly array $owners = [],
        private readonly string $step = '',
    ) {}

    public function spaceId(): string
    {
        return $this->space->id;
    }

    /** 'pgsql', 'mysql' or 'sqlite' — for the rare driver-specific branch inside a step. */
    public function driver(): string
    {
        return $this->writer->connection()->getDriverName();
    }

    public function idStrategy(): IdStrategy
    {
        return $this->space->idStrategy;
    }

    /** Application-side physical name of a logical table this step may use. */
    public function table(string $logical): string
    {
        if (! in_array($logical, $this->referenceable, true)) {
            throw new LogicException("Schema component [{$this->component}] cannot use table [{$logical}]: not its own and not a dependency's.");
        }

        return $this->space->table($logical);
    }

    /** Quoted database name of a logical table (connection prefix included) for driver-specific raw DDL. */
    public function wrapTable(string $logical): string
    {
        return $this->writer->connection()->getSchemaGrammar()->wrapTable($this->table($logical));
    }

    /** Deterministic <=63-byte name of an index/constraint/object of a logical table. */
    public function name(string $logical, string $suffix): string
    {
        $this->table($logical);

        return SchemaNames::identifier($this->space->databaseTable($logical), $suffix);
    }

    /** @param  Closure(Blueprint): void  $define */
    public function create(string $logical, Closure $define): void
    {
        $this->assertOwned($logical);
        $this->writer->create($this->space->table($logical), fn (Blueprint $table) => $this->define($table, $define));
        $this->flush();
    }

    /** @param  Closure(Blueprint): void  $define */
    public function alter(string $logical, Closure $define): void
    {
        $this->assertOwned($logical);
        $this->writer->alter($this->space->table($logical), fn (Blueprint $table) => $this->define($table, $define));
        $this->flush();
    }

    public function drop(string $logical): void
    {
        $this->assertOwned($logical);
        $this->writer->drop($this->space->table($logical));
    }

    /** Primary key column of the space's id strategy. Must be the first call in create(). */
    public function key(Blueprint $table, string $column = 'id'): void
    {
        match ($this->space->idStrategy) {
            IdStrategy::Uuid7 => $table->uuid($column),
            IdStrategy::Ulid => $table->char($column, 26),
            IdStrategy::Bigint => $table->bigIncrements($column),
        };

        if ($this->space->idStrategy !== IdStrategy::Bigint) {
            $table->primary([$column]);
        }
    }

    /** Column holding the id of a row of this space (same type as key()); no index — declare one explicitly. */
    public function reference(Blueprint $table, string $column, bool $nullable = false): ColumnDefinition
    {
        $definition = match ($this->space->idStrategy) {
            IdStrategy::Uuid7 => $table->uuid($column),
            IdStrategy::Ulid => $table->char($column, 26),
            IdStrategy::Bigint => $table->unsignedBigInteger($column),
        };

        return $definition->nullable($nullable);
    }

    /**
     * An actor reference: "{role}_type" (alias, <=32) + "{role}_id" (<=64 bytes).
     */
    public function actor(Blueprint $table, string $role, bool $nullable = false): void
    {
        $type = $table->string("{$role}_type", 32)->nullable($nullable);
        $id = $table->string("{$role}_id", 64)->nullable($nullable);

        if ($this->driver() === 'mysql') {
            $type->collation('utf8mb4_0900_bin');
            $id->collation('utf8mb4_0900_bin');
        }
    }

    /** Counter, position, revision, attempt or size: unsigned 64-bit. $default null = no default. */
    public function counter(Blueprint $table, string $column, ?int $default = 0, bool $nullable = false): ColumnDefinition
    {
        $definition = $table->unsignedBigInteger($column)->nullable($nullable);

        return $default === null ? $definition : $definition->default($default);
    }

    /** Point in time. */
    public function time(Blueprint $table, string $column, bool $nullable = true): ColumnDefinition
    {
        return $table->dateTime($column)->nullable($nullable);
    }

    public function timestamps(Blueprint $table): void
    {
        $this->time($table, 'created_at');
        $this->time($table, 'updated_at');
    }

    /** @param  list<string>  $columns  $algorithm e.g. 'gin' (PostgreSQL) */
    public function index(Blueprint $table, array $columns, string $suffix, ?string $algorithm = null): void
    {
        $table->index($columns, $this->register($table, $suffix), $algorithm);
    }

    /** @param  list<string>  $columns */
    public function unique(Blueprint $table, array $columns, string $suffix): void
    {
        $table->unique($columns, $this->register($table, $suffix));
    }

    /**
     * @param  string|list<string>  $columns
     * @param  'cascade'|'set null'|'restrict'  $onDelete
     */
    public function foreign(Blueprint $table, string|array $columns, string $logicalTarget, string $suffix, string $onDelete = 'cascade', string $references = 'id'): void
    {
        if (! in_array($logicalTarget, $this->referenceable, true)) {
            throw new LogicException("SCHEMA_FK_UNDECLARED [{$this->component}] {$this->logicalName($table)} -> {$logicalTarget}: add the owner to dependsOn()");
        }

        $table->foreign($columns, $this->register($table, $suffix))
            ->references($references)
            ->on($this->table($logicalTarget))
            ->onDelete($onDelete);
    }

    /** Named CHECK constraint (PostgreSQL, MySQL). SQLite is a development database without it. */
    public function check(Blueprint $table, string $suffix, string $expression): void
    {
        $this->pending[] = new SchemaObject(SchemaObject::CHECK, $this->register($table, $suffix), $table->getTable(), $expression);
    }

    /**
     * CHECK "{column} IN (...)" over string literals `^[a-z0-9_.:-]+$`.
     *
     * @param  non-empty-list<string>  $values
     */
    public function checkIn(Blueprint $table, string $column, array $values): void
    {
        foreach ($values as $value) {
            if (preg_match('/^[a-z0-9_.:-]+$/', $value) !== 1) {
                throw new LogicException("CHECK literal [{$value}] must match ^[a-z0-9_.:-]+\$.");
            }
        }

        $this->check($table, "{$column}_check", $column." IN ('".implode("', '", $values)."')");
    }

    /** A driver-specific function or trigger, created after the current table. */
    public function object(string $kind, string $name, string $logical, string $up, string $down): void
    {
        $this->assertOwned($logical);
        $this->pending[] = new SchemaObject($kind, $name, $this->space->table($logical), '', $up, $down);
        $this->flush();
    }

    public function dropObject(string $kind, string $name, string $logical, string $down): void
    {
        $this->assertOwned($logical);
        $this->writer->dropObject(new SchemaObject($kind, $name, $this->space->table($logical), '', '', $down));
    }

    /** @param  Closure(Connection): void  $work */
    public function backfill(Closure $work): void
    {
        $this->writer->backfill($work);
    }

    /** @return array<string, string|null> */
    public function stateRow(): array
    {
        return $this->space->stateRow();
    }

    /** @param  Closure(Blueprint): void  $define */
    private function define(Blueprint $table, Closure $define): void
    {
        $define($table);

        foreach ($table->getColumns() as $column) {
            $columnName = Values::string($column->get('name'));
            foreach (['index', 'unique', 'primary', 'fulltext', 'spatialIndex'] as $fluent) {
                if ($column->get($fluent) !== null && $column->get($fluent) !== false) {
                    throw new LogicException("Column [{$table->getTable()}.{$columnName}] uses fluent ->{$fluent}(); declare it through SpaceSchema.");
                }
            }
        }

        $covered = [];

        foreach ($table->getColumns() as $column) {
            $columnName = Values::string($column->get('name'));

            if ($column->get('autoIncrement', false) === true) {
                $covered[] = [$columnName];
            }
        }

        $commands = array_filter($table->getCommands(), static fn (object $command): bool => ! $command instanceof ColumnDefinition);

        foreach ($commands as $command) {
            $commandName = Values::string($command->get('name'));
            $indexName = Values::string($command->get('index'));

            if (in_array($commandName, ['index', 'unique', 'foreign'], true) && ! isset($this->named[$indexName])) {
                throw new LogicException("[{$table->getTable()}] {$commandName} [{$indexName}] must be declared through SpaceSchema::{$commandName}().");
            }

            if (in_array($commandName, ['index', 'unique', 'primary'], true)) {
                $covered[] = Values::strings($command->get('columns'));
            }
        }

        foreach ($commands as $command) {
            $commandName = Values::string($command->get('name'));
            $indexName = Values::string($command->get('index'));

            if ($commandName !== 'foreign') {
                continue;
            }

            $columns = Values::strings($command->get('columns'));
            $isCovered = false;

            foreach ($covered as $indexColumns) {
                if (array_slice($indexColumns, 0, count($columns)) === $columns) {
                    $isCovered = true;
                }
            }

            if (! $isCovered) {
                throw new LogicException("[{$table->getTable()}] foreign key [{$indexName}] columns (".implode(', ', $columns).') lead no index.');
            }
        }
    }

    private function register(Blueprint $table, string $suffix): string
    {
        $name = SchemaNames::identifier($this->space->connectionPrefix.$table->getTable(), $suffix);
        $this->named[$name] = true;

        return $name;
    }

    private function flush(): void
    {
        foreach ($this->pending as $object) {
            $this->writer->createObject($object);
        }

        $this->pending = [];
    }

    private function assertOwned(string $logical): void
    {
        if (! in_array($logical, $this->owned, true)) {
            $step = $this->step === '' ? '' : " step {$this->step}";

            throw new LogicException("SCHEMA_FOREIGN_TABLE [{$this->component}]{$step} touches {$logical} owned by ".($this->owners[$logical] ?? 'no component'));
        }
    }

    private function logicalName(Blueprint $table): string
    {
        foreach ($this->owned as $logical) {
            if ($this->space->table($logical) === $table->getTable()) {
                return $logical;
            }
        }

        return $table->getTable();
    }
}
