<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Lifecycle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Str;
use LogicException;
use Packman\Context\Contracts\ActorResolverInterface;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Exceptions\ScopeUnavailableException;
use Packman\Features\Contracts\FeatureFlagsInterface;
use Packman\Modules\Contracts\Definition\ModuleDiscovery;
use Packman\Modules\Contracts\Lifecycle\ModuleActivationGate;
use Packman\Modules\Contracts\Lifecycle\ModuleLifecycle;
use Packman\Modules\Contracts\Lifecycle\RecordsRegistrar;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Data\Lifecycle\ModuleManifest;
use Packman\Modules\Data\Lifecycle\ModuleRuntimeContext;
use Packman\Modules\Data\Lifecycle\ModuleStatus;
use Packman\Modules\Enums\Lifecycle\DisableReason;
use Packman\Modules\Enums\Lifecycle\ModuleState;
use Packman\Modules\Events\Lifecycle\ModuleArchived;
use Packman\Modules\Events\Lifecycle\ModuleDisabled;
use Packman\Modules\Events\Lifecycle\ModuleEnabled;
use Packman\Modules\Events\Lifecycle\ModulePurged;
use Packman\Modules\Events\Lifecycle\ModuleUpgraded;
use Packman\Modules\Exceptions\Lifecycle\HasEnabledDependentsException;
use Packman\Modules\Exceptions\Lifecycle\InvalidStateTransitionException;
use Packman\Modules\Exceptions\Lifecycle\MissingDependencyException;
use Packman\Modules\Exceptions\Lifecycle\ModuleEnableFailedException;
use Packman\Modules\Exceptions\Lifecycle\ModuleUpgradeFailedException;
use Packman\Modules\Exceptions\Registry\ModuleNotFound;
use Packman\Modules\ModulesServiceProvider;
use Psr\Clock\ClockInterface;
use stdClass;
use Throwable;

/**
 * DB-backed {@see ModuleLifecycle}. Every transition is wrapped in
 * `pg_advisory_xact_lock(hashtext($module))` (xact-scoped, released on
 * commit): a second concurrent caller blocks on the lock, then sees the
 * already-applied state and no-ops.
 *
 * enable() is all-or-nothing: a failing migration or EnableHook rolls
 * the WHOLE transition back (migrations 1..K included — PG DDL is
 * transactional) and rethrows {@see ModuleEnableFailedException}. No partial
 * install, no orphan `mod_modules` row; a fixed retry starts clean.
 *
 * Ownership claims are written AUTOMATICALLY: a per-migration schema
 * diff attributes every table/column/index a module's migrations create to
 * that module ({@see ModuleMigrationClaims}), so purge can tear them all down
 * in reverse creation order — no hand-written `claim()` calls to drift out of
 * sync with the real schema.
 *
 * `$codeVersions`/`$migrationPaths` (composer name → value) are populated from
 * `vendor/composer/installed.json` by {@see ModulesServiceProvider},
 * so real deploys run each module's migrations; tests inject them directly.
 *
 * @internal spec: B-10 §3.3/§5.1/§5.3/§5.4, B-10 §7.3 п.3, AC-11, D40, AC-7, D41
 */
final readonly class DatabaseModuleLifecycle implements ModuleLifecycle
{
    private const string MODULES_TABLE = 'mod_modules';

    private const string RECORDS_TABLE = 'mod_records';

    private const string LOG_TABLE = 'mod_lifecycle_log';

    /**
     * @param  array<string, string>  $codeVersions
     * @param  array<string, string>  $migrationPaths
     * @param  ?string  $connection  null = the app's default connection (production is PG-only, so that's correct there; tests pin an explicit name to avoid ambient-default mutation).
     *
     * @internal spec: D12
     */
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleDiscovery $discovery,
        private RecordsRegistrar $records,
        private Container $container,
        private ContextResolverInterface $tenantContextResolver,
        private ConnectionResolverInterface $connections,
        private EventDispatcher $events,
        private ClockInterface $clock,
        private array $codeVersions = [],
        private array $migrationPaths = [],
        private ?string $connection = null,
        private ?ActorResolverInterface $actorResolver = null,
        private string $lockKey = 'modules:lifecycle-graph',
        private string $modulesTable = self::MODULES_TABLE,
        private string $recordsTable = self::RECORDS_TABLE,
        private string $lifecycleLogTable = self::LOG_TABLE,
    ) {}

    private function db(): Connection
    {
        /** @var Connection */
        $connection = $this->connections->connection($this->connection);

        return $connection;
    }

    /**
     * Microsecond-precision timestamp string. Laravel's query-builder date
     * format is second-precision (Grammar::getDateFormat()) — passing a
     * bare now() Carbon instance as a bound value truncates it. Ordering of
     * claims/log rows no longer relies on this (monotonic surrogates are used
     * instead), but the columns still carry an accurate wall-clock stamp.
     *
     * @internal spec: D31/D42
     */
    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function enable(string $module, string $edition = 'standard', bool $cascade = false): void
    {
        $compiled = $this->registry->get($module); // ModuleNotFound if not a deployed module
        $tenant = $this->requireTenantContext();

        $this->db()->transaction(function () use ($module, $edition, $cascade, $tenant, $compiled): void {
            $this->lock($module);

            $current = $this->currentRow($module);

            if ($current !== null && $current->state === ModuleState::Enabled->value) {
                return; // lock serialized us behind the winner — no-op.
            }

            // FSM gate (A11/A12): `purged` is terminal. Its tables are dropped
            // and its migrations are still logged as "ran", so a direct enable
            // would flip the row to enabled over a missing schema. Re-install
            // goes through `modules:repair` → available → enable.
            if ($current !== null && $current->state === ModuleState::Purged->value) {
                throw InvalidStateTransitionException::purgedIsTerminal($module);
            }

            $decision = $this->container->make(ModuleActivationGate::class)->check($tenant, $module, $edition);

            if (! $decision->allowed) {
                throw new AuthorizationException($decision->reason);
            }

            foreach ($compiled->requires as $dep) {
                if ($dep['kind'] === 'module') {
                    $this->ensureDependencyEnabled(module: $module, target: $dep['package'], cascade: $cascade);
                }
            }

            $fromState = $current->state ?? ModuleState::Available->value;
            $context = new ModuleRuntimeContext(module: $module, tenant: $tenant, records: $this->records);

            $migration = $this->runModuleMigrations($module);

            if ($migration['error'] === null) {
                try {
                    $this->invokeHook(hookClass: $this->manifestFor($module)->onEnableHook, context: $context);
                } catch (Throwable $e) {
                    $migration['error'] = $e;
                }
            }

            // All-or-nothing: any failure aborts the whole transaction —
            // migrations 1..K, claims and the log row all roll back — and
            // surfaces to the caller. No silent commit, no half-installed row.
            if ($migration['error'] instanceof Throwable) {
                throw new ModuleEnableFailedException(module: $module, previous: $migration['error']);
            }

            $this->upsertModuleRow(module: $module, current: $current, state: ModuleState::Enabled, edition: $edition, migrationsRun: $migration['ran']);

            $this->defineManifestFeatureFlags($module);

            $this->log(module: $module, operation: 'enable', from: $fromState, to: ModuleState::Enabled->value, status: 'success', details: [
                'migrations_run' => $migration['ran'],
            ]);

            $this->db()->afterCommit(function () use ($module, $edition, $migration): void {
                $this->events->dispatch(new ModuleEnabled(module: $module, edition: $edition, migrationsRun: $migration['ran']));
            });
        });
    }

    private function requireTenantContext(): ContextScopeInterface
    {
        return $this->tenantContextResolver->current() ?? throw new ScopeUnavailableException;
    }

    /**
     * `ModuleManifest::$featureFlags` → `FeatureFlagsInterface::define()` — the manifest
     * definitions participate in the enable transaction and are visible before
     * its after-commit notification. The bound store must use this connection.
     * `define()` is register-once and preserves administrator overrides.
     *
     * @internal spec: P1.25, A68, P1.22
     */
    private function defineManifestFeatureFlags(string $module): void
    {
        $featureFlags = $this->manifestFor($module)->featureFlags;

        if ($featureFlags === []) {
            return;
        }

        $previous = $this->connections->getDefaultConnection();
        $this->connections->setDefaultConnection($this->connection ?? $previous);

        try {
            $flags = $this->container->make(FeatureFlagsInterface::class);

            foreach ($featureFlags as $definition) {
                $flags->define($definition);
            }
        } finally {
            $this->connections->setDefaultConnection($previous);
        }
    }

    public function disable(string $module, DisableReason $reason, bool $cascade = false): void
    {
        $tenant = $this->requireTenantContext();

        $this->db()->transaction(function () use ($module, $reason, $cascade, $tenant): void {
            $this->lock($module);

            $current = $this->currentRow($module);

            if ($current === null || $current->state !== ModuleState::Enabled->value) {
                return; // nothing enabled to disable — no-op.
            }

            $dependents = $this->enabledDependents($module);

            if ($dependents !== [] && ! $cascade) {
                throw new HasEnabledDependentsException(module: $module, dependents: $dependents);
            }

            foreach ($dependents as $dependent) {
                $this->disable(module: $dependent, reason: DisableReason::Dependency, cascade: true);
            }

            $context = new ModuleRuntimeContext(module: $module, tenant: $tenant, records: $this->records);
            $this->invokeHook(hookClass: $this->manifestFor($module)->onDisableHook, context: $context);

            $this->db()->table($this->modulesTable)->where('name', $module)->update([
                'state' => ModuleState::Disabled->value,
                'disable_reason' => $reason->value,
                'disabled_at' => $this->timestamp(),
                'updated_at' => $this->timestamp(),
            ]);

            $this->log(module: $module, operation: 'disable', from: ModuleState::Enabled->value, to: ModuleState::Disabled->value, status: 'success', details: [
                'reason' => $reason->value,
            ]);

            $this->db()->afterCommit(fn () => $this->events->dispatch(new ModuleDisabled(module: $module, reason: $reason)));
        });
    }

    public function uninstall(string $module, bool $keepData = true): void
    {
        $tenant = $this->requireTenantContext();

        $this->db()->transaction(function () use ($module, $keepData, $tenant): void {
            $this->lock($module);

            $current = $this->currentRow($module);

            if ($current === null) {
                return; // nothing to uninstall.
            }

            if (! in_array($current->state, [ModuleState::Disabled->value, ModuleState::Archived->value], true)) {
                throw new LogicException(sprintf(
                    'Module [%s] must be disabled before uninstall (current state: %s) — B-10 §5.1 two-step safety.',
                    $module,
                    $current->state,
                ));
            }

            if ($keepData) {
                $this->db()->table($this->modulesTable)->where('name', $module)->update([
                    'state' => ModuleState::Archived->value,
                    'updated_at' => $this->timestamp(),
                ]);

                $this->log(module: $module, operation: 'archive', from: $current->state, to: ModuleState::Archived->value, status: 'success', details: []);

                $this->db()->afterCommit(fn () => $this->events->dispatch(new ModuleArchived($module)));

                return;
            }

            $this->purge(module: $module, fromState: $current->state, tenant: $tenant);
        });
    }

    public function upgrade(string $module): void
    {
        $this->db()->transaction(function () use ($module): void {
            $this->lock($module);

            $current = $this->currentRow($module);

            if ($current === null) {
                throw new ModuleNotFound($module);
            }

            // FSM gate: catch-up migrations only make sense for a live module.
            if ($current->state !== ModuleState::Enabled->value) {
                throw InvalidStateTransitionException::upgradeRequiresEnabled(module: $module, state: $current->state);
            }

            $migration = $this->runModuleMigrations($module);
            $codeVersion = $this->codeVersion($module);

            // All-or-nothing, symmetric to enable(): a failing
            // catch-up migration aborts the WHOLE transition — migrations 1..K,
            // the version/schema_version bump and the log row all roll back — and
            // surfaces to the caller. The older code committed
            // `schema_version=end(ran)` and logged 'failed' WITHOUT rethrowing,
            // leaving the module silently half-migrated and the caller none the
            // wiser (the symmetric silent-failure this closes).
            if ($migration['error'] instanceof Throwable) {
                throw new ModuleUpgradeFailedException(module: $module, previous: $migration['error']);
            }

            $fromVersion = $current->version;

            $this->db()->table($this->modulesTable)->where('name', $module)->update([
                'version' => $codeVersion,
                'schema_version' => $migration['ran'] !== [] ? end($migration['ran']) : $current->schema_version,
                'updated_at' => $this->timestamp(),
            ]);

            $this->log(module: $module, operation: 'upgrade', from: $current->state, to: $current->state, status: 'success', details: [
                'migrations_run' => $migration['ran'],
                'from_version' => $fromVersion,
                'to_version' => $codeVersion,
            ]);

            $this->db()->afterCommit(fn () => $this->events->dispatch(new ModuleUpgraded(module: $module, fromVersion: $fromVersion, toVersion: $codeVersion, migrationsRun: $migration['ran'])));
        });
    }

    public function repair(string $module): void
    {
        $this->db()->transaction(function () use ($module): void {
            $this->lock($module);

            // Reconcile the ownership registry with the real schema: drop claims
            // whose table/column/index vanished out of band.
            $this->reconcileClaims($module);

            $current = $this->currentRow($module);

            if ($current === null || $current->state !== ModuleState::Purged->value) {
                return; // a healthy (or already-available) module keeps its row.
            }

            // Legal exit from the `purged` dead end: reset the module to
            // `available` so a fresh enable() re-runs its migrations from
            // scratch ("purged → enabled: как первый"). Forgetting the
            // migration history is what makes that re-run actually apply the DDL
            // again instead of skipping it as already-ran.
            $this->forgetModuleMigrations($module);
            $this->db()->table($this->recordsTable)->where('module_name', $module)->delete();
            $this->db()->table($this->modulesTable)->where('name', $module)->delete();
        });
    }

    public function status(string $module): ModuleStatus
    {
        $current = $this->currentRow($module);
        $codeVersion = $this->codeVersion($module);

        if ($current === null) {
            return new ModuleStatus(name: $module, state: ModuleState::Available, codeVersion: $codeVersion, dbVersion: null, pendingUpgrade: false, edition: 'standard');
        }

        return new ModuleStatus(
            name: $module,
            state: ModuleState::from($current->state),
            codeVersion: $codeVersion,
            dbVersion: $current->version,
            pendingUpgrade: version_compare($codeVersion, $current->version, '>'),
            edition: $current->edition,
        );
    }

    // ─── Internals ──────────────────────────────────────────────────────

    private function lock(string $module): void
    {
        $this->db()->statement('select pg_advisory_xact_lock(hashtext(?))', [$this->lockKey]);
        $this->db()->statement('select pg_advisory_xact_lock(hashtext(?))', [$module]);
    }

    /** @return (stdClass&object{state: string, version: string, schema_version: string|null, edition: string})|null */
    private function currentRow(string $module): ?object
    {
        $row = $this->db()->table($this->modulesTable)->where('name', $module)->first();

        if ($row !== null) {
            assert(is_string($row->state));
            assert(is_string($row->version));
            assert(is_string($row->schema_version) || $row->schema_version === null);
            assert(is_string($row->edition));
        }

        /** @var (stdClass&object{state: string, version: string, schema_version: string|null, edition: string})|null $row Columns checked against the owned mod_modules schema above. */
        return $row;
    }

    private function ensureDependencyEnabled(string $module, string $target, bool $cascade): void
    {
        $targetState = $this->currentRow($target)?->state;

        if ($targetState === ModuleState::Enabled->value) {
            return;
        }

        if (! $cascade) {
            throw new MissingDependencyException(module: $module, missingDependency: $target);
        }

        $this->enable(module: $target, cascade: true);
    }

    /** @return list<string> */
    private function enabledDependents(string $module): array
    {
        $dependents = [];

        foreach ($this->discovery->discover() as $manifest) {
            foreach ($manifest->requires as $dep) {
                if ($dep->kind !== 'module' || $dep->package !== $module) {
                    continue;
                }

                if ($this->currentRow($manifest->composerName)?->state === ModuleState::Enabled->value) {
                    $dependents[] = $manifest->composerName;
                }

                break;
            }
        }

        return $dependents;
    }

    private function purge(string $module, string $fromState, ContextScopeInterface $tenant): void
    {
        // Defensive FSM guard: a purged module cannot leave live dependents
        // behind (disable already blocks that, but purge asserts it too).
        $dependents = $this->enabledDependents($module);

        if ($dependents !== []) {
            throw new HasEnabledDependentsException(module: $module, dependents: $dependents);
        }

        $context = new ModuleRuntimeContext(module: $module, tenant: $tenant, records: $this->records);
        $this->invokeHook(hookClass: $this->manifestFor($module)->onPurgeHook, context: $context);

        // Tear every owned object down in REVERSE creation order (`seq`,
        // not the clock), across ALL claim types, so FK children drop
        // before parents and no schema object (table/column/index) is orphaned.
        $claims = $this->db()->table($this->recordsTable)
            ->where('module_name', $module)
            ->orderByDesc('seq')
            ->get(['record_type', 'reference']);

        $droppedTables = [];

        foreach ($claims as $claim) {
            assert(is_string($claim->reference));
            match ($claim->record_type) {
                'table' => $this->dropOwnedTable(table: $claim->reference, droppedTables: $droppedTables),
                'column' => $this->dropOwnedColumn($claim->reference),
                'index' => $this->dropOwnedIndex($claim->reference),
                // role/permission/menu/setting/seed/blueprint/feature_flag/other
                // are logical claims with no core-owned schema sink in P1 (roles
                // are aut_* in P2, menus land later): their teardown is the
                // PurgeHook above + the ownership-row delete below. They carry no
                // information_schema footprint, so the "0 orphans" AC still holds.
                default => null,
            };
        }

        $this->db()->table($this->recordsTable)->where('module_name', $module)->delete();

        $this->db()->table($this->modulesTable)->where('name', $module)->update([
            'state' => ModuleState::Purged->value,
            'updated_at' => $this->timestamp(),
        ]);

        $this->log(module: $module, operation: 'purge', from: $fromState, to: ModuleState::Purged->value, status: 'success', details: [
            'dropped_tables' => $droppedTables,
        ]);

        $this->db()->afterCommit(fn () => $this->events->dispatch(new ModulePurged(module: $module, droppedTables: $droppedTables)));
    }

    /** @param  list<string>  $droppedTables */
    private function dropOwnedTable(string $table, array &$droppedTables): void
    {
        $this->db()->getSchemaBuilder()->dropIfExists($table);
        $droppedTables[] = $table;
    }

    private function dropOwnedColumn(string $reference): void
    {
        [$table, $column] = array_pad(explode('.', $reference, 2), 2, null);

        if ($table === null || $column === null) {
            return;
        }

        $this->db()->statement(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS %s', $this->quoteIdentifier($table), $this->quoteIdentifier($column)));
    }

    private function dropOwnedIndex(string $index): void
    {
        $this->db()->statement(sprintf('DROP INDEX IF EXISTS %s', $this->quoteIdentifier($index)));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function reconcileClaims(string $module): void
    {
        $objects = new ModuleMigrationClaims(connection: $this->db(), records: $this->records, events: $this->container->make('events'))->schemaObjects();

        $claims = $this->db()->table($this->recordsTable)
            ->where('module_name', $module)
            ->whereIn('record_type', ['table', 'column', 'index'])
            ->get(['record_type', 'reference']);

        foreach ($claims as $claim) {
            assert(is_string($claim->reference));
            $reference = $claim->reference;

            $exists = match ($claim->record_type) {
                'table' => isset($objects['tables'][$reference]),
                'column' => isset($objects['columns'][$reference]),
                'index' => isset($objects['indexes'][$reference]),
                default => true,
            };

            if ($exists) {
                continue;
            }

            $this->db()->table($this->recordsTable)
                ->where('module_name', $module)
                ->where('record_type', $claim->record_type)
                ->where('reference', $reference)
                ->delete();
        }
    }

    private function forgetModuleMigrations(string $module): void
    {
        $path = $this->migrationPaths[$module] ?? null;

        if ($path === null) {
            return;
        }

        $names = array_map(
            static fn (string $file): string => basename($file, '.php'),
            (($files = glob(rtrim($path, '/').'/*.php')) === false ? [] : $files),
        );

        if ($names !== []) {
            /** @var Migrator $migrator */
            $migrator = $this->container->make('migrator');
            $migrator->usingConnection($this->connection ?? $this->connections->getDefaultConnection(), static function () use ($migrator, $names): void {
                $repository = $migrator->getRepository();
                foreach ($names as $name) {
                    $repository->delete((object) ['migration' => $name]);
                }
            });
        }
    }

    private function invokeHook(?string $hookClass, ModuleRuntimeContext $context): void
    {
        if ($hookClass === null) {
            return;
        }

        /** @var callable(ModuleRuntimeContext):void $hook */
        $hook = $this->container->make($hookClass);
        $hook($context);
    }

    private function manifestFor(string $module): ModuleManifest
    {
        foreach ($this->discovery->discover() as $manifest) {
            if ($manifest->composerName === $module) {
                return $manifest;
            }
        }

        throw new ModuleNotFound($module);
    }

    /**
     * Runs every pending migration under the module's registered migrations
     * path via Laravel's own migrator/repository, wrapping each migration in a
     * schema-diff that auto-claims the tables/columns/indexes it creates
     * ({@see ModuleMigrationClaims}) — no hand-written claim() in migrations.
     *
     * @return array{ran: list<string>, error: ?Throwable}
     */
    private function runModuleMigrations(string $module): array
    {
        $path = $this->migrationPaths[$module] ?? null;

        if ($path === null) {
            return ['ran' => [], 'error' => null];
        }

        /** @var Migrator $migrator */
        $migrator = $this->container->make('migrator');

        return $migrator->usingConnection($this->connection ?? $this->connections->getDefaultConnection(), function () use ($migrator, $path, $module): array {
            $repository = $migrator->getRepository();

            if (! $repository->repositoryExists()) {
                $repository->createRepository();
            }

            $before = $repository->getRan();

            $claims = new ModuleMigrationClaims(connection: $this->db(), records: $this->records, events: $this->container->make('events'));
            $error = $claims->run(moduleForMigration: static fn (Migration $migration): string => $module, run: static function () use ($migrator, $path): void {
                $migrator->run([$path]);
            });

            $after = $repository->getRan();

            return ['ran' => array_values(array_diff($after, $before)), 'error' => $error];
        });
    }

    private function codeVersion(string $module): string
    {
        return $this->codeVersions[$module] ?? '0.0.0';
    }

    /** @param  list<string>  $migrationsRun */
    private function upsertModuleRow(string $module, ?object $current, ModuleState $state, string $edition, array $migrationsRun): void
    {
        $now = $this->timestamp();
        $schemaVersion = $migrationsRun !== [] ? end($migrationsRun) : ($current->schema_version ?? null);

        if ($current !== null) {
            $this->db()->table($this->modulesTable)->where('name', $module)->update([
                'state' => $state->value,
                'version' => $this->codeVersion($module),
                'schema_version' => $schemaVersion,
                'edition' => $edition,
                'enabled_at' => $now,
                'disabled_at' => null,
                'disable_reason' => null,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->db()->table($this->modulesTable)->insert([
            'id' => (string) Str::uuid7(),
            'name' => $module,
            'state' => $state->value,
            'version' => $this->codeVersion($module),
            'schema_version' => $schemaVersion,
            'edition' => $edition,
            'enabled_at' => $now,
            'disabled_at' => null,
            'disable_reason' => null,
            'config' => json_encode([], JSON_THROW_ON_ERROR),
            'meta' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Append a lifecycle-log row. Beyond the audit trail, this insert
     * is the registry EPOCH bump: every terminal transition
     * (enable/disable/upgrade/archive/purge) calls log() INSIDE this
     * transaction, so the append-only row count in `mod_lifecycle_log` is a
     * monotonic per-account registry version bumped atomically with the state
     * change. The hot-path cache keys off that count
     * ({@see ModulesServiceProvider::registryVersion()}), so a
     * transition here invalidates every process's/node's cached enabled-set
     * without any forget(). Removing a log() call therefore un-bumps the epoch
     * and leaves other processes serving a stale enabled-set.
     *
     * Actor is sourced from {@see ActorResolverInterface} when bound, falling back to `system`.
     *
     * @param  array<string, mixed>  $details
     *
     * @internal spec: AC-13, D39, B-10 §3.1, P1, P2
     */
    private function log(string $module, string $operation, string $from, string $to, string $status, array $details): void
    {
        $actor = $this->actorResolver?->current();

        $this->db()->table($this->lifecycleLogTable)->insert([
            'id' => (string) Str::uuid7(),
            'module_name' => $module,
            'operation' => $operation,
            'from_state' => $from,
            'to_state' => $to,
            'status' => $status,
            'actor_type' => $actor->type ?? 'system',
            'actor_id' => $actor?->id,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => $this->timestamp(),
            'updated_at' => $this->timestamp(),
        ]);
    }
}
