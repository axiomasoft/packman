<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packman\Schema\Contracts\Migration\ModuleMigrationBoundary;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Internal\Migration\TaggedSchemaSets;
use Packman\Schema\Tests\Fixtures\FixtureSchemaSets;

uses()->group('pg');

beforeEach(function (): void {
    DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS public CASCADE');
    DB::connection('pgsql')->statement('CREATE SCHEMA public');
    DB::purge('pgsql');
});

it('migrates platform sets on PostgreSQL and reports idempotent no-op reruns', function (): void {
    $platformPath = realpath(__DIR__.'/../Fixtures/migrations/platform');
    assert(is_string($platformPath));
    $registry = new TaggedSchemaSets([
        new FixtureSchemaSets([
            new SchemaSet('fixture/platform', 'tenant', SchemaScope::Tenant, $platformPath, 1, ['pgsql', 'sqlite']),
        ]),
    ]);
    app()->instance(SchemaSets::class, $registry);
    app()->forgetInstance(SchemaMigrator::class);

    $this->migrateSchemaSets(['fixture/platform:tenant'], 'pgsql');
    expect(Schema::connection('pgsql')->hasTable('schema_platform'))->toBeTrue()
        ->and(resolve(SchemaMigrator::class)->migrate(SchemaScope::Tenant, 'pgsql'))->toBe([]);
});

it('reads the PostgreSQL journal and reports pending files on the selected connection', function (): void {
    $platformPath = realpath(__DIR__.'/../Fixtures/migrations/platform');
    assert(is_string($platformPath));
    $registry = new TaggedSchemaSets([
        new FixtureSchemaSets([
            new SchemaSet('fixture/platform', 'tenant', SchemaScope::Tenant, $platformPath, 1, ['pgsql', 'sqlite']),
        ]),
    ]);
    app()->instance(SchemaSets::class, $registry);
    app()->forgetInstance(SchemaMigrator::class);

    $this->migrateSchemaScope(SchemaScope::Tenant, 'pgsql', includeModules: false);
    foreach (resolve(SchemaMigrator::class)->status(SchemaScope::Tenant, 'pgsql') as $status) {
        expect($status->pending)->toBe([])->and($status->unknown)->toBe([]);
    }
    DB::connection('pgsql')->table('migrations')->where('migration', '2026_10_01_980010_create_schema_platform')->delete();
    $this->artisan('packman:schema:status', ['--database' => 'pgsql'])->assertExitCode(1);
});

it('ignores central legacy journal entries when tenant sets share one PostgreSQL database', function (): void {
    $centralPath = realpath(__DIR__.'/../Fixtures/migrations/dedicated');
    $platformPath = realpath(__DIR__.'/../Fixtures/migrations/platform');
    assert(is_string($centralPath) && is_string($platformPath));

    $registry = new TaggedSchemaSets([
        new FixtureSchemaSets([
            new SchemaSet('fixture/dedicated', 'central', SchemaScope::Central, $centralPath, 1, ['pgsql', 'sqlite']),
            new SchemaSet('fixture/platform', 'tenant', SchemaScope::Tenant, $platformPath, 1, ['pgsql', 'sqlite']),
        ]),
    ]);
    app()->instance(SchemaSets::class, $registry);
    app()->forgetInstance(SchemaMigrator::class);

    $this->migrateSchemaSets(['fixture/dedicated:central'], 'pgsql');
    $this->migrateSchemaSets(['fixture/platform:tenant'], 'pgsql');

    $status = resolve(SchemaMigrator::class)->status(SchemaScope::Tenant, 'pgsql')[0];
    expect($status->unknown)->toBe([])->and($status->pending)->toBe([]);
    $this->artisan('packman:schema:status', ['--database' => 'pgsql'])->assertSuccessful();
});

it('routes PostgreSQL module migrations through the registered ModuleMigrationBoundary and runs prerequisite set', function (SchemaScope $moduleScope): void {
    $platformPath = realpath(__DIR__.'/../Fixtures/migrations/platform');
    $modulePath = realpath(__DIR__.'/../Fixtures/migrations/module');
    assert(is_string($platformPath) && is_string($modulePath));

    $registry = new TaggedSchemaSets([
        new FixtureSchemaSets([
            new SchemaSet('fixture/prerequisite', 'tenant', SchemaScope::Tenant, $platformPath, 1, ['pgsql', 'sqlite']),
            new SchemaSet('fixture/module', 'tenant', $moduleScope, $modulePath, 1, ['pgsql', 'sqlite'], SchemaLifecycle::Module),
        ]),
    ]);
    app()->instance(SchemaSets::class, $registry);
    config(['packman.schema.module_prerequisite_set' => 'fixture/prerequisite:tenant']);

    $captured = [];
    app()->instance(ModuleMigrationBoundary::class, new class($captured) implements ModuleMigrationBoundary
    {
        /** @param array<string, mixed> $captured */
        public function __construct(public array &$captured) {}

        public function run(Connection $connection, string $connectionName, array $owners, Closure $run): void
        {
            $this->captured['connection'] = $connectionName;
            $this->captured['owners'] = $owners;
            $connection->transaction($run);
        }
    });
    app()->forgetInstance(SchemaMigrator::class);

    $this->migrateSchemaSets(['fixture/module:tenant'], 'pgsql');

    $expectedFile = realpath($modulePath.'/2026_10_01_980030_create_schema_module.php');
    expect(Schema::connection('pgsql')->hasTable('schema_platform'))->toBe($moduleScope === SchemaScope::Tenant)
        ->and(Schema::connection('pgsql')->hasTable('schema_module'))->toBeTrue()
        ->and($captured['connection'])->toBe('pgsql')
        ->and($captured['owners'])->toBe([$expectedFile => 'fixture/module']);
})->with([SchemaScope::Tenant, SchemaScope::Central]);

it('refuses PostgreSQL module migrations when ModuleMigrationBoundary is missing', function (): void {
    $modulePath = realpath(__DIR__.'/../Fixtures/migrations/module');
    assert(is_string($modulePath));
    $registry = new TaggedSchemaSets([
        new FixtureSchemaSets([
            new SchemaSet('fixture/module', 'tenant', SchemaScope::Tenant, $modulePath, 1, ['pgsql', 'sqlite'], SchemaLifecycle::Module),
        ]),
    ]);
    app()->instance(SchemaSets::class, $registry);
    app()->offsetUnset(ModuleMigrationBoundary::class);
    config(['packman.schema.module_prerequisite_set' => null]);
    app()->forgetInstance(SchemaMigrator::class);

    expect(static fn () => resolve(SchemaMigrator::class)->migrateSets(['fixture/module:tenant'], 'pgsql'))
        ->toThrow(LogicException::class, 'Module schema ownership requires the registered module migration boundary.');
});
