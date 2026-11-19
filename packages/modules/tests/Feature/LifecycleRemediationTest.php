<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Features\Contracts\FeatureFlagsInterface;
use Packman\Modules\Contracts\Lifecycle\ModuleActivationGate;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Data\Compiler\CompiledModule;
use Packman\Modules\Data\Lifecycle\ModuleActivationDecision;
use Packman\Modules\Enums\Lifecycle\DisableReason;
use Packman\Modules\Events\Lifecycle\ModuleEnabled;
use Packman\Modules\Exceptions\Lifecycle\ModuleEnableFailedException;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Modules\Internal\Lifecycle\DatabaseModuleLifecycle;
use Packman\Modules\Internal\Lifecycle\DatabaseRecordsRegistrar;
use Packman\Modules\Internal\Schema\ModulesSchemaSets;
use Packman\Modules\ModulesServiceProvider;
use Packman\Modules\Tests\Stubs\Lifecycle\StubBaseModule;
use Packman\Modules\Tests\Stubs\Lifecycle\StubFailingModule;
use Packman\Modules\Tests\Stubs\Lifecycle\StubFeatureFlagModule;
use Packman\Modules\Tests\Stubs\LifecycleStringMigrationListener;
use Packman\Modules\Tests\Stubs\StubDatabaseFeatureFlags;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Internal\Migration\TaggedSchemaSets;
use Psr\Clock\ClockInterface;

uses()->group('pg');

beforeEach(function (): void {
    config(['database.default' => 'pgsql', 'modules.connection' => 'pgsql']);
    DB::statement('DROP SCHEMA IF EXISTS public CASCADE');
    DB::statement('CREATE SCHEMA public');
    $this->app->register(ModulesServiceProvider::class);
    $this->migrateSchemaSets(['axiomasoft/packman-modules:tenant'], 'pgsql');
    StubDatabaseFeatureFlags::ensureTable('pgsql');
    $discovery = new ModuleDiscovery(fallbackModules: [StubBaseModule::class, StubFailingModule::class, StubFeatureFlagModule::class]);
    $registry = Mockery::mock(ModuleRegistry::class);
    foreach ($discovery->discover() as $manifest) {
        $registry->shouldReceive('get')->with($manifest->composerName)->andReturn(CompiledModule::fromManifest($manifest));
    }
    $this->app->instance(ModuleRegistry::class, $registry);
    $this->app->instance(FeatureFlagsInterface::class, new StubDatabaseFeatureFlags('pgsql'));
    $this->lifecycle = new DatabaseModuleLifecycle(
        registry: $registry, discovery: $discovery, records: app()->make(DatabaseRecordsRegistrar::class, ['connection' => 'pgsql']),
        container: app(), tenantContextResolver: resolve(ContextResolverInterface::class), connections: resolve(ConnectionResolverInterface::class), events: resolve(Dispatcher::class), clock: resolve(ClockInterface::class),
        migrationPaths: [
            'packman/stub-base' => __DIR__.'/../Stubs/Lifecycle/migrations/base',
            'packman/stub-failing' => __DIR__.'/../Stubs/Lifecycle/migrations/failing',
        ], connection: 'pgsql',
    );
});

it('honors a denying activation gate before migrations and hooks', function (): void {
    $gate = Mockery::mock(ModuleActivationGate::class);
    $gate->shouldReceive('check')->once()->andReturn(ModuleActivationDecision::deny('host policy'));
    app()->instance(ModuleActivationGate::class, $gate);
    expect(fn () => $this->lifecycle->enable('packman/stub-base'))->toThrow(AuthorizationException::class, 'host policy');
    expect(DB::table('mod_modules')->count())->toBe(0)
        ->and(Schema::hasTable('sba_widgets'))->toBeFalse();
});

it('serializes different module transitions on the dependency graph lock', function (): void {
    config(['database.connections.module_race' => config('database.connections.pgsql')]);
    DB::connection('module_race')->beginTransaction();
    DB::connection('module_race')->statement("SET LOCAL lock_timeout = '50ms'");
    $other = new DatabaseModuleLifecycle(
        registry: resolve(ModuleRegistry::class), discovery: new ModuleDiscovery(fallbackModules: [StubFeatureFlagModule::class]),
        records: app()->make(DatabaseRecordsRegistrar::class, ['connection' => 'module_race']), container: app(),
        tenantContextResolver: resolve(ContextResolverInterface::class), connections: resolve(ConnectionResolverInterface::class), events: resolve(Dispatcher::class), clock: resolve(ClockInterface::class), connection: 'module_race',
    );
    $observed = false;
    DB::listen(function ($query) use (&$observed, $other): void {
        if ($observed || $query->connectionName !== 'pgsql' || ! in_array('modules:lifecycle-graph', $query->bindings, true)) {
            return;
        }
        $observed = true;

        try {
            $other->enable('packman/stub-feature-flag');
            test()->fail('Different modules mutated the graph concurrently.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[0])->toBe('55P03');
        }
    });

    try {
        $this->lifecycle->enable('packman/stub-base');
        expect($observed)->toBeTrue();
        $other->enable('packman/stub-feature-flag');
        DB::connection('module_race')->commit();
        expect(DB::table('mod_modules')->count())->toBe(2);
    } finally {
        if (DB::connection('module_race')->transactionLevel() > 0) {
            DB::connection('module_race')->rollBack();
        }
        DB::purge('module_race');
    }
});

it('preserves application migration listeners on success and failure', function (): void {
    $started = $ended = 0;
    LifecycleStringMigrationListener::$calls = 0;
    $listener = LifecycleStringMigrationListener::class.'@onStarted';
    resolve('events')->listen(MigrationStarted::class, $listener);
    resolve('events')->listen(MigrationStarted::class, function () use (&$started): void {
        $started++;
    });
    resolve('events')->listen(MigrationEnded::class, function () use (&$ended): void {
        $ended++;
    });
    $this->lifecycle->enable('packman/stub-base');
    expect($started)->toBeGreaterThan(0)->and($ended)->toBeGreaterThan(0);
    expect(fn () => $this->lifecycle->enable('packman/stub-failing'))->toThrow(ModuleEnableFailedException::class);
    $before = [$started, $ended];
    $migration = new class extends Migration {};
    resolve('events')->dispatch(new MigrationStarted($migration, 'up'));
    resolve('events')->dispatch(new MigrationEnded($migration, 'up'));
    expect([$started, $ended])->toBe([$before[0] + 1, $before[1] + 1]);
    expect(LifecycleStringMigrationListener::$calls)->toBe($started)
        ->and(resolve('events')->getRawListeners()[MigrationStarted::class])->toContain($listener);
});

it('makes flags visible to ModuleEnabled and rolls back a failed definition', function (): void {
    $flags = Mockery::mock(FeatureFlagsInterface::class);
    $flags->shouldReceive('define')->once()->andThrow(new RuntimeException('definition failed'));
    app()->instance(FeatureFlagsInterface::class, $flags);
    expect(fn () => $this->lifecycle->enable('packman/stub-feature-flag'))->toThrow(RuntimeException::class, 'definition failed');
    expect(DB::table('mod_modules')->count())->toBe(0);
    app()->instance(FeatureFlagsInterface::class, new StubDatabaseFeatureFlags);
    // Lifecycle is pinned to PG; ambient SQLite has no feature store.
    config(['database.default' => 'testbench']);
    $seen = false;
    resolve('events')->listen(ModuleEnabled::class, function () use (&$seen): void {
        $seen = DB::connection('pgsql')->table('sys_feature_flags')->where('key', 'sff.beta_mode')->exists();
    });
    $this->lifecycle->enable('packman/stub-feature-flag');
    expect($seen)->toBeTrue();
    config(['database.default' => 'pgsql']);
    DB::table('sys_feature_flags')->where('key', 'sff.beta_mode')->update(['is_enabled' => true]);
    $this->lifecycle->disable('packman/stub-feature-flag', DisableReason::Manual);
    $this->lifecycle->enable('packman/stub-feature-flag');
    expect(DB::table('sys_feature_flags')->where('key', 'sff.beta_mode')->count())->toBe(1);
    expect(resolve(FeatureFlagsInterface::class)->active('sff.beta_mode'))->toBeTrue();
});

it('rolls back feature definitions and enable notifications with an outer transaction', function (): void {
    $notified = false;
    resolve('events')->listen(ModuleEnabled::class, function () use (&$notified): void {
        $notified = true;
    });
    DB::beginTransaction();
    $this->lifecycle->enable('packman/stub-feature-flag');
    expect(DB::table('sys_feature_flags')->count())->toBe(1)->and($notified)->toBeFalse();
    DB::rollBack();
    expect(DB::table('mod_modules')->count())->toBe(0)
        ->and(DB::table('sys_feature_flags')->count())->toBe(0)->and($notified)->toBeFalse();
});

it('uses the current physical default when lifecycle connection is unpinned', function (): void {
    app()->instance(FeatureFlagsInterface::class, new StubDatabaseFeatureFlags);
    $lifecycle = new DatabaseModuleLifecycle(
        registry: resolve(ModuleRegistry::class), discovery: new ModuleDiscovery(fallbackModules: [StubFeatureFlagModule::class]),
        records: resolve(DatabaseRecordsRegistrar::class), container: app(), tenantContextResolver: resolve(ContextResolverInterface::class), connections: resolve(ConnectionResolverInterface::class), events: resolve(Dispatcher::class), clock: resolve(ClockInterface::class),
    );
    $lifecycle->enable('packman/stub-feature-flag');
    expect(DB::table('sys_feature_flags')->where('key', 'sff.beta_mode')->exists())->toBeTrue()
        ->and(DB::getDefaultConnection())->toBe('pgsql');
});

it('preserves template module ownership through clone enable purge and repair', function (): void {
    $path = __DIR__.'/../Stubs/Lifecycle/migrations/base';
    $set = new SchemaSet(
        package: 'packman/stub-base', name: 'tenant', scope: SchemaScope::Tenant,
        path: $path, version: 1, lifecycle: SchemaLifecycle::Module,
    );
    $provider = new readonly class($set) implements SchemaSetProvider
    {
        public function __construct(private SchemaSet $set) {}

        public function sets(): array
        {
            return [$this->set];
        }
    };
    app()->instance(SchemaSets::class, new TaggedSchemaSets([new ModulesSchemaSets, $provider]));
    app()->forgetInstance(SchemaMigrator::class);
    $migrator = resolve(SchemaMigrator::class);
    $template = 'packman_module_template_'.bin2hex(random_bytes(6)).'_test';
    $clone = 'packman_template_clone_'.bin2hex(random_bytes(6)).'_test';
    config(['database.connections.clone_admin' => [...config('database.connections.pgsql'), 'database' => 'postgres']]);
    $admin = DB::connection('clone_admin');
    $admin->statement('CREATE DATABASE "'.$template.'"');
    config(['database.connections.module_template' => [...config('database.connections.pgsql'), 'database' => $template]]);

    try {
        expect(DB::connection('module_template')->getDatabaseName())->toBe($template);
        Schema::connection('module_template')->create('host_preserved', fn ($table) => $table->id());
        $migrator->migrate(SchemaScope::Tenant, 'module_template', true);
        expect(DB::connection('module_template')->table('mod_records')->where('module_name', 'packman/stub-base')->where('record_type', 'table')->count())->toBe(2);
        $migrator->migrateSets([$set->id()], 'module_template');
        DB::disconnect('module_template');
        $admin->statement('CREATE DATABASE "'.$clone.'" TEMPLATE "'.$template.'"');
        config(['database.connections.module_clone' => [...config('database.connections.pgsql'), 'database' => $clone]]);
        $db = DB::connection('module_clone');
        expect($db->getDatabaseName())->toBe($clone);
        $lifecycle = new DatabaseModuleLifecycle(
            registry: resolve(ModuleRegistry::class), discovery: new ModuleDiscovery(fallbackModules: [StubBaseModule::class]),
            records: app()->make(DatabaseRecordsRegistrar::class, ['connection' => 'module_clone']), container: app(),
            tenantContextResolver: resolve(ContextResolverInterface::class), connections: resolve(ConnectionResolverInterface::class),
            events: resolve(Dispatcher::class), clock: resolve(ClockInterface::class),
            migrationPaths: ['packman/stub-base' => $path], connection: 'module_clone',
        );
        app()->instance(FeatureFlagsInterface::class, new StubDatabaseFeatureFlags('module_clone'));
        $lifecycle->enable('packman/stub-base');
        $lifecycle->upgrade('packman/stub-base');
        $lifecycle->disable('packman/stub-base', DisableReason::Manual);
        $lifecycle->uninstall('packman/stub-base', keepData: false);
        expect($lifecycle->status('packman/stub-base')->state->value)->toBe('purged')
            ->and($db->getSchemaBuilder()->hasTable('sba_widgets'))->toBeFalse()
            ->and($db->getSchemaBuilder()->hasTable('host_preserved'))->toBeTrue()
            ->and($db->getSchemaBuilder()->hasTable('mod_modules'))->toBeTrue()
            ->and($db->table('mod_records')->where('module_name', 'packman/stub-base')->count())->toBe(0)
            ->and(DB::connection('module_template')->getSchemaBuilder()->hasTable('sba_widgets'))->toBeTrue();
        $lifecycle->repair('packman/stub-base');
        $lifecycle->enable('packman/stub-base');
        expect($db->getSchemaBuilder()->hasTable('sba_widgets'))->toBeTrue()
            ->and($db->table('mod_records')->where('module_name', 'packman/stub-base')->where('record_type', 'table')->count())->toBe(2);
    } finally {
        DB::purge('module_clone');
        $admin->statement('DROP DATABASE IF EXISTS "'.$clone.'" WITH (FORCE)');
        DB::purge('module_template');
        $admin->statement('DROP DATABASE "'.$template.'" WITH (FORCE)');
        DB::purge('clone_admin');
    }
});
