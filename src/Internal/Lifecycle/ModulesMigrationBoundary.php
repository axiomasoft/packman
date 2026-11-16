<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Lifecycle;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use LogicException;
use Packman\Modules\Internal\Support\ModulesOptions;
use Packman\Schema\Contracts\Migration\ModuleMigrationBoundary;
use Psr\Clock\ClockInterface;
use ReflectionClass;

/**
 * Runs module-owned migrations under the advisory lifecycle graph lock and records automatic DDL claims.
 *
 * @internal
 */
final readonly class ModulesMigrationBoundary implements ModuleMigrationBoundary
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ClockInterface $clock,
        private ?ModulesOptions $options = null,
    ) {}

    public function run(Connection $connection, string $connectionName, array $owners, Closure $run): void
    {
        $events = $connection->getEventDispatcher()
            ?? throw new LogicException('Module schema ownership requires the Laravel migration dispatcher.');

        $lockKey = $this->options->lockKey ?? 'modules:lifecycle-graph';
        $recordsTable = $this->options->recordsTable ?? 'mod_records';

        $connection->transaction(function () use ($connection, $connectionName, $events, $owners, $run, $lockKey, $recordsTable): void {
            $connection->statement('select pg_advisory_xact_lock(hashtext(?))', [$lockKey]);

            $claims = new ModuleMigrationClaims(
                connection: $connection,
                records: new DatabaseRecordsRegistrar(
                    connections: $this->connections,
                    clock: $this->clock,
                    connection: $connectionName,
                    recordsTable: $recordsTable,
                ),
                events: $events,
            );

            $error = $claims->run(
                moduleForMigration: static fn (Migration $migration): ?string => self::migrationOwner($migration, $owners),
                run: $run,
            );

            if ($error !== null) {
                throw $error;
            }
        });
    }

    /** @param  array<string, string>  $owners */
    private static function migrationOwner(Migration $migration, array $owners): ?string
    {
        $file = new ReflectionClass($migration)->getFileName();

        if ($file === false) {
            return null;
        }

        $canonical = realpath($file);

        return $owners[$canonical === false ? $file : $canonical] ?? null;
    }
}
