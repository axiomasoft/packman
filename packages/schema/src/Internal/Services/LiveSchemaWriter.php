<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Services;

use Closure;
use Illuminate\Database\Connection;
use Packman\Schema\Internal\Contracts\SchemaWriter;
use Packman\Schema\Internal\Data\SchemaObject;

/** Executes a step's DDL on the space connection. */
final readonly class LiveSchemaWriter implements SchemaWriter
{
    public function __construct(private Connection $connection)
    {
        $connection->getSchemaBuilder();
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function create(string $table, Closure $define): void
    {
        $this->connection->getSchemaBuilder()->create($table, $define);
    }

    public function alter(string $table, Closure $define): void
    {
        $this->connection->getSchemaBuilder()->table($table, $define);
    }

    public function drop(string $table): void
    {
        $this->connection->getSchemaBuilder()->dropIfExists($table);
    }

    public function createObject(SchemaObject $object): void
    {
        if ($object->kind !== SchemaObject::CHECK) {
            $this->connection->statement($object->up);

            return;
        }

        if ($this->connection->getDriverName() === 'sqlite') {
            return;
        }

        $grammar = $this->connection->getSchemaGrammar();
        $this->connection->statement(sprintf(
            'alter table %s add constraint %s check (%s)',
            $grammar->wrapTable($object->table),
            $grammar->wrap($object->name),
            $object->expression,
        ));
    }

    public function dropObject(SchemaObject $object): void
    {
        if ($object->kind !== SchemaObject::CHECK) {
            $this->connection->statement($object->down);

            return;
        }

        $grammar = $this->connection->getSchemaGrammar();
        $sql = match ($this->connection->getDriverName()) {
            'mysql' => 'alter table %s drop check %s',
            'pgsql' => 'alter table %s drop constraint if exists %s',
            default => null,
        };

        if ($sql !== null) {
            $this->connection->statement(sprintf($sql, $grammar->wrapTable($object->table), $grammar->wrap($object->name)));
        }
    }

    public function backfill(Closure $work): void
    {
        $work($this->connection);
    }
}
