<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Migration;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Data\Migration\SchemaMigrationStatus;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Exceptions\SchemaSetException;

/** @internal */
#[Internal]
final readonly class DefaultSchemaMigrator implements SchemaMigrator
{
    /**
     * @param  ?Closure(Connection, string, array<string, string>, Closure):void  $moduleMigrations
     * @param  ?Closure():bool  $isActiveHost
     */
    public function __construct(
        private SchemaSets $sets,
        private Migrator $migrator,
        private DatabaseManager $db,
        private ?Closure $moduleMigrations = null,
        private ?string $prerequisiteSetId = 'axiomasoft/packman-modules:tenant',
        private ?Closure $isActiveHost = null,
    ) {}

    public function migrate(SchemaScope $scope, ?string $connection = null, bool $includeModules = false): array
    {
        if ($scope === SchemaScope::Dedicated && $connection !== null) {
            throw new InvalidArgumentException('Dedicated schema sets run on their own connections.');
        }

        return $this->run(sets: $this->sets->forScope(scope: $scope, includeModules: $includeModules), connection: $connection);
    }

    public function migrateSets(array $ids, ?string $connection = null): array
    {
        $sets = array_map($this->sets->get(...), array_values(array_unique($ids)));
        $scopes = array_unique(array_map(static fn (SchemaSet $set): string => $set->scope->value, $sets));

        if (count($scopes) > 1) {
            throw new InvalidArgumentException('Explicit schema sets must belong to one scope.');
        }

        return $this->run(sets: $sets, connection: $connection);
    }

    public function status(SchemaScope $scope, ?string $connection = null): array
    {
        if ($scope === SchemaScope::Dedicated && $connection !== null) {
            throw new InvalidArgumentException('Dedicated schema sets run on their own connections.');
        }
        $sets = $this->sets->forScope(scope: $scope, includeModules: true);
        $groups = $this->groups(sets: $sets, connection: $connection);
        $known = array_keys($this->migrator->getMigrationFiles(array_map(static fn (SchemaSet $set): string => $set->path, $this->sets->all())));
        $result = [];
        foreach ($groups as $name => $group) {
            $this->migrator->usingConnection($name, function () use ($group, $known, &$result): void {
                $repository = $this->migrator->getRepository();
                $ran = $repository->repositoryExists() ? $repository->getRan() : [];
                foreach ($group as $set) {
                    $files = array_keys($this->migrator->getMigrationFiles([$set->path]));
                    // Baseline names reserve a two-digit package prefix in the sequence component.
                    $prefixes = [];
                    foreach ($files as $file) {
                        if (preg_match('/^2026_10_01_(\d{2})\d{4}_/', $file, $match) === 1) {
                            $prefixes[] = $match[1];
                        }
                    }
                    $unknown = array_values(array_filter(array_diff($ran, $known), static fn (string $file): bool => preg_match('/^\d{4}_\d{2}_\d{2}_(\d{2})\d{4}_/', $file, $match) === 1 && in_array($match[1], $prefixes, true)));
                    $result[] = new SchemaMigrationStatus(set: $set, applied: array_values(array_intersect($files, $ran)), pending: array_values(array_diff($files, $ran)), unknown: $unknown);
                }
            });
        }
        usort($result, static fn (SchemaMigrationStatus $a, SchemaMigrationStatus $b): int => strcmp($a->set->id(), $b->set->id()));

        return $result;
    }

    /**
     * Validate the entire selection before touching any migration repository.
     *
     * @param  list<SchemaSet>  $sets
     * @return array<string, list<SchemaSet>>
     */
    private function groups(array $sets, ?string $connection): array
    {
        $groups = [];
        foreach ($sets as $set) {
            if ($set->scope === SchemaScope::Dedicated && $connection !== null) {
                throw new InvalidArgumentException('Dedicated schema sets run on their own connections.');
            }
            $name = $set->connection ?? $connection ?? $this->db->getDefaultConnection();
            $driver = $this->db->connection($name)->getDriverName();

            if (! in_array($driver, $set->drivers, true)) {
                throw SchemaSetException::unsupportedDriver(id: $set->id(), driver: $driver);
            }
            $groups[$name][] = $set;
        }

        return $groups;
    }

    /**
     * @param  list<SchemaSet>  $sets
     * @return list<string>
     */
    private function run(array $sets, ?string $connection): array
    {
        if ($this->isActiveHost !== null && ! ($this->isActiveHost)()) {
            throw new LogicException('Run schema migrations in the Laravel application that owns the migrator.');
        }

        $groups = $this->groups(sets: $sets, connection: $connection);
        $plans = [];
        foreach ($groups as $name => $group) {
            $db = $this->db->connection($name);
            $bootstrap = $this->legacyPrerequisite($group, $db);
            $plan = new SchemaMigrationPlan($this->sets, $db->getDriverName(), $bootstrap);
            $plans[$name] = $plan->batches($group);
        }

        $ran = [];
        foreach ($plans as $name => $batches) {
            $ran = [...$ran, ...$this->migrator->usingConnection($name, function () use ($batches, $name): array {
                $db = $this->db->connection($name);
                $repository = $this->migrator->getRepository();

                if (! $repository->repositoryExists()) {
                    $repository->createRepository();
                }
                $before = $repository->getRan();
                $owners = [];
                foreach (array_merge(...$batches) as $set) {
                    if ($set->lifecycle === SchemaLifecycle::Module) {
                        foreach ($this->migrator->getMigrationFiles([$set->path]) as $file) {
                            $canonical = realpath($file);
                            $owners[$canonical === false ? $file : $canonical] = $set->package;
                        }
                    }
                }
                $run = function () use ($batches): void {
                    foreach ($batches as $batch) {
                        $this->migrator->run(array_map(static fn (SchemaSet $set): string => $set->path, $batch));
                    }
                };

                if ($owners === [] || $db->getDriverName() === 'sqlite') {
                    $run();
                } else {
                    if ($db->getDriverName() !== 'pgsql') {
                        throw new LogicException('Module schema ownership requires PostgreSQL.');
                    }

                    if ($this->moduleMigrations === null) {
                        throw new LogicException('Module schema ownership requires the registered module migration boundary.');
                    }
                    ($this->moduleMigrations)($db, $name, $owners, $run);
                }

                return array_values(array_diff($repository->getRan(), $before));
            })];
        }

        return $ran;
    }

    /** @param list<SchemaSet> $group */
    private function legacyPrerequisite(array $group, Connection $db): ?SchemaSet
    {
        if ($this->prerequisiteSetId === null || $this->prerequisiteSetId === ''
            || $db->getDriverName() !== 'pgsql'
            || ! array_any($group, static fn (SchemaSet $set): bool => $set->lifecycle === SchemaLifecycle::Module)
            || $db->getSchemaBuilder()->hasTable('mod_records')) {
            return null;
        }

        foreach ($this->sets->all() as $set) {
            if ($set->id() === $this->prerequisiteSetId) {
                return $set;
            }
        }

        return null;
    }
}
