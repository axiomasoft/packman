<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Lifecycle;

use Closure;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Events\Dispatcher;
use Illuminate\Events\QueuedClosure;
use Illuminate\Support\Testing\Fakes\EventFake;
use LogicException;
use Packman\Modules\Contracts\RecordsRegistrar;
use stdClass;
use Throwable;

/** Shared ownership boundary for lifecycle and template module migrations. */
final readonly class ModuleMigrationClaims
{
    private Dispatcher $events;

    /**
     * Listeners are attached to and removed from the concrete Laravel dispatcher. `Event::fake()`
     * wraps it: listen() is forwarded but forget() is a no-op, so the wrapped instance is used.
     */
    public function __construct(private Connection $connection, private RecordsRegistrar $records, EventDispatcher $events)
    {
        $concrete = $events instanceof EventFake ? $events->dispatcher : $events;

        if (! $concrete instanceof Dispatcher) {
            throw new LogicException('Module schema ownership requires the Laravel event dispatcher.');
        }

        $this->events = $concrete;
    }

    /**
     * @param  Closure(Migration):?string  $moduleForMigration
     *                                                          Auto-claim wrapper: snapshots the schema around each `up()` and
     *                                                          attributes the new tables/columns/indexes to $module. Claims are written
     *                                                          in creation order (tables by pg_class.oid), so the bigserial `seq` on
     *                                                          `mod_records` encodes creation order and purge is FK-safe. The listeners
     *                                                          are removed by identity in `finally`, preserving application listeners,
     *                                                          including listeners registered during a migration.
     *
     * @internal spec: D41
     */
    public function run(Closure $moduleForMigration, Closure $run): ?Throwable
    {
        $events = $this->events;
        $module = null;

        /** @var array{tables: array<string, int>, columns: array<string, bool>, indexes: array<string, string>}|null $snapshot */
        $snapshot = null;

        $started = function (MigrationStarted $event) use (&$snapshot, &$module, $moduleForMigration): void {
            if ($event->method === 'up') {
                $module = $moduleForMigration($event->migration);
                $snapshot = $module === null ? null : $this->schemaObjects();
            }
        };

        $ended = function (MigrationEnded $event) use (&$snapshot, &$module): void {
            if ($event->method !== 'up' || $snapshot === null || $module === null) {
                return;
            }

            $this->claimSchemaDelta(module: $module, before: $snapshot, after: $this->schemaObjects());
            $snapshot = null;
        };

        $events->listen(MigrationStarted::class, $started);
        $events->listen(MigrationEnded::class, $ended);

        try {
            $run();

            return null;
        } catch (Throwable $error) {
            return $error;
        } finally {
            foreach ([MigrationStarted::class => $started, MigrationEnded::class => $ended] as $event => $owned) {
                $listeners = $events->getRawListeners()[$event] ?? [];
                assert(is_array($listeners));
                $events->forget($event);
                foreach ($listeners as $listener) {
                    assert(is_callable($listener) || is_string($listener) || is_array($listener) || $listener instanceof QueuedClosure);

                    if ($listener !== $owned) {
                        if (is_string($listener)) {
                            $this->restoreStringListener(events: $events, event: $event, listener: $listener);
                        } else {
                            $events->listen($event, $listener);
                        }
                    }
                }
            }
        }
    }

    private function restoreStringListener(EventDispatcher $events, string $event, string $listener): void
    {
        // The interface accepts Laravel's Class@method form; the concrete PHPDoc only lists class-string.
        $events->listen($event, $listener);
    }

    /**
     * @param  array{tables: array<string, int>, columns: array<string, bool>, indexes: array<string, string>}  $before
     * @param  array{tables: array<string, int>, columns: array<string, bool>, indexes: array<string, string>}  $after
     */
    private function claimSchemaDelta(string $module, array $before, array $after): void
    {
        // New tables first, in creation (oid) order — a child table created
        // after its parent gets the higher seq and therefore drops first.
        $newTables = array_diff_key($after['tables'], $before['tables']);
        asort($newTables);

        foreach (array_keys($newTables) as $table) {
            $this->records->claim(module: $module, recordType: 'table', reference: $table);
        }

        // Columns added to a table that ALREADY existed (cross-module extension
        // — a module adds a column to another module's table). Columns of
        // a brand-new table need no separate claim: dropping the table drops
        // them.
        foreach (array_keys($after['columns']) as $key) {
            if (isset($before['columns'][$key])) {
                continue;
            }

            $table = explode('.', $key, 2)[0];

            if (isset($before['tables'][$table])) {
                $this->records->claim(module: $module, recordType: 'column', reference: $key);
            }
        }

        // Indexes created on a pre-existing table (same reasoning as columns).
        foreach ($after['indexes'] as $index => $table) {
            if (isset($before['indexes'][$index])) {
                continue;
            }

            if (isset($before['tables'][$table])) {
                $this->records->claim(module: $module, recordType: 'index', reference: $index);
            }
        }
    }

    /**
     * Current-schema tables (name → oid), columns ("table.column" → true) and
     * indexes (name → table) on the module connection. oid is a monotonic
     * creation-order proxy (pg_class); index/column membership lets the diff
     * tell a brand-new table apart from an extension of an existing one.
     *
     * @return array{tables: array<string, int>, columns: array<string, bool>, indexes: array<string, string>}
     */
    public function schemaObjects(): array
    {
        $connection = $this->connection;

        $tables = [];

        foreach ($connection->select("select c.relname as name, c.oid::bigint as oid from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.relkind = 'r' and n.nspname = current_schema()") as $row) {
            assert($row instanceof stdClass && is_string($row->name) && (is_int($row->oid) || is_numeric($row->oid)));
            $tables[$row->name] = (int) $row->oid;
        }

        $columns = [];

        foreach ($connection->select('select table_name, column_name from information_schema.columns where table_schema = current_schema()') as $row) {
            assert($row instanceof stdClass && is_string($row->table_name) && is_string($row->column_name));
            $columns[$row->table_name.'.'.$row->column_name] = true;
        }

        $indexes = [];

        foreach ($connection->select("select i.relname as name, t.relname as tbl from pg_class i join pg_index ix on ix.indexrelid = i.oid join pg_class t on t.oid = ix.indrelid join pg_namespace n on n.oid = i.relnamespace where i.relkind = 'i' and n.nspname = current_schema()") as $row) {
            assert($row instanceof stdClass && is_string($row->name) && is_string($row->tbl));
            $indexes[$row->name] = $row->tbl;
        }

        return ['tables' => $tables, 'columns' => $columns, 'indexes' => $indexes];
    }
}
