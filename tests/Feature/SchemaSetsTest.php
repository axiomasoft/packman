<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Exceptions\SchemaSetException;
use Packman\Schema\Internal\Migration\TaggedSchemaSets;
use Packman\Schema\Laravel\SchemaExtensions;
use Packman\Schema\Tests\Fixtures\FixtureSchemaSets;

final readonly class StatelessDoubleTaggedSchemaSetProvider implements SchemaSetProvider
{
    public function sets(): array
    {
        return [schemaFixture()];
    }
}

final class StatefulZeroArgSchemaSetProvider implements SchemaSetProvider
{
    public string $dataset = 'platform';

    public function sets(): array
    {
        return [schemaFixture($this->dataset)];
    }
}

/**
 * @param  list<string>  $drivers
 * @param  list<string>  $requires
 */
function schemaFixture(string $name = 'platform', SchemaScope $scope = SchemaScope::Tenant, array $drivers = ['pgsql', 'sqlite'], array $requires = [], ?string $connection = null): SchemaSet
{
    $path = realpath(__DIR__.'/../Fixtures/migrations/'.$name);
    assert(is_string($path));

    return new SchemaSet(
        package: 'fixture/'.$name,
        name: 'tenant',
        scope: $scope,
        path: $path,
        version: 1,
        drivers: $drivers,
        lifecycle: $name === 'module' ? SchemaLifecycle::Module : SchemaLifecycle::Platform,
        requires: $requires,
        connection: $connection,
    );
}

function schemaRegistry(SchemaSet ...$sets): SchemaSets
{
    $registry = new TaggedSchemaSets([new FixtureSchemaSets(array_values($sets))]);
    app()->instance(SchemaSets::class, $registry);
    app()->forgetInstance(SchemaMigrator::class);

    return $registry;
}

it('sorts two providers and excludes module sets unless requested', function (): void {
    $platform = schemaFixture();
    $module = schemaFixture('module');
    $dedicated = schemaFixture('dedicated', SchemaScope::Dedicated, connection: 'schema_second');
    $sets = new TaggedSchemaSets([new FixtureSchemaSets([$platform, $dedicated]), new FixtureSchemaSets([$module])]);
    expect(array_map(static fn (SchemaSet $set): string => $set->id(), $sets->all()))->toBe(['fixture/dedicated:tenant', 'fixture/module:tenant', 'fixture/platform:tenant'])
        ->and($sets->forScope(SchemaScope::Tenant, false))->toBe([$platform])
        ->and($sets->forScope(SchemaScope::Tenant, true))->toBe([$module, $platform])
        ->and($sets->moduleSet('fixture/module'))->toBe($module);
});

it('rejects duplicate identities', function (): void {
    schemaRegistry(schemaFixture(), schemaFixture());
})->throws(SchemaSetException::class, 'duplicate_set');

it('rejects duplicate migration basenames in one scope', function (): void {
    $first = schemaFixture();
    schemaRegistry($first, new SchemaSet('fixture/second', 'tenant', SchemaScope::Tenant, $first->path, 1));
})->throws(SchemaSetException::class, 'duplicate_migration');

it('activates a conditional set only when its required package contributes sets', function (): void {
    $conditional = schemaFixture('module', requires: ['fixture/platform']);
    expect(schemaRegistry($conditional)->all())->toBe([])
        ->and(schemaRegistry($conditional, schemaFixture())->all())->toHaveCount(2);
});

it('rejects invalid definitions and missing paths', function (): void {
    foreach ([['version' => 0], ['drivers' => ['sqlite']], ['connection' => 'other'], ['scope' => SchemaScope::Dedicated], ['path' => '/nonexistent/packman-schema']] as $changes) {
        try {
            $set = new SchemaSet(...array_replace(['package' => 'fixture/invalid', 'name' => 'tenant', 'scope' => SchemaScope::Tenant, 'path' => schemaFixture()->path, 'version' => 1], $changes));
            schemaRegistry($set);
            test()->fail('Invalid schema set was accepted.');
        } catch (SchemaSetException $error) {
            expect($error->getMessage())->toContain('invalid_set');
        }
    }
});

it('preflights all selected drivers before creating the migration journal', function (): void {
    schemaRegistry(schemaFixture(), schemaFixture('module', drivers: ['pgsql']));
    $before = Schema::hasTable('migrations');

    try {
        resolve(SchemaMigrator::class)->migrate(SchemaScope::Tenant, includeModules: true);
        test()->fail('Unsupported driver was accepted.');
    } catch (SchemaSetException $error) {
        expect($error->getMessage())->toContain('unsupported_driver')
            ->and(Schema::hasTable('schema_platform'))->toBeFalse()
            ->and(Schema::hasTable('migrations'))->toBe($before);
    }
});

it('migrates and reports pending applied and unknown journal entries', function (): void {
    schemaRegistry(schemaFixture());
    $this->artisan('packman:schema:status')->assertExitCode(1);
    $this->artisan('packman:schema:migrate')->assertSuccessful();
    expect(Schema::hasTable('schema_platform'))->toBeTrue();
    $this->artisan('packman:schema:migrate')->expectsOutput('Nothing to migrate.')->assertSuccessful();
    $this->artisan('packman:schema:status')->assertSuccessful();
    DB::table('migrations')->insert(['migration' => '2026_10_01_980020_missing', 'batch' => 2]);
    expect(resolve(SchemaMigrator::class)->status(SchemaScope::Tenant)[0]->unknown)->toBe(['2026_10_01_980020_missing']);
    $this->artisan('packman:schema:status')->assertExitCode(1);
    DB::table('migrations')->where('migration', '2026_10_01_980020_missing')->delete();
    DB::table('migrations')->where('migration', '2026_10_01_980010_create_schema_platform')->delete();
    $this->artisan('packman:schema:status')->assertExitCode(1);
});

it('leaves module sets pending until explicitly included', function (): void {
    schemaRegistry(schemaFixture(), schemaFixture('module'));
    $this->artisan('packman:schema:migrate')->assertSuccessful();
    expect(Schema::hasTable('schema_module'))->toBeFalse();
    $this->artisan('packman:schema:migrate', ['--modules' => true])->assertSuccessful();
    expect(Schema::hasTable('schema_module'))->toBeTrue();
});

it('runs dedicated sets on their own connection and restores the ambient connection', function (): void {
    config(['database.connections.schema_second' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    schemaRegistry(schemaFixture('dedicated', SchemaScope::Dedicated, connection: 'schema_second'));
    $default = DB::getDefaultConnection();
    $this->migrateSchemaSets(['fixture/dedicated:tenant']);
    expect(Schema::connection('schema_second')->hasTable('schema_dedicated'))->toBeTrue()
        ->and(Schema::hasTable('schema_dedicated'))->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe($default);
    $this->artisan('packman:schema:status', ['--scope' => 'dedicated'])->assertSuccessful();
});

it('rejects dedicated connection overrides through all entry points', function (): void {
    config(['database.connections.schema_second' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    schemaRegistry(schemaFixture('dedicated', SchemaScope::Dedicated, connection: 'schema_second'));
    $migrator = resolve(SchemaMigrator::class);
    foreach ([
        static fn (): array => $migrator->migrate(SchemaScope::Dedicated, 'testbench'),
        static fn (): array => $migrator->migrateSets(['fixture/dedicated:tenant'], 'testbench'),
        static fn (): array => $migrator->status(SchemaScope::Dedicated, 'testbench'),
    ] as $call) {
        try {
            $call();
            test()->fail('Dedicated connection override was accepted.');
        } catch (InvalidArgumentException $error) {
            expect($error->getMessage())->toContain('own connections');
        }
    }
});

it('refuses mixed scopes and unknown sets before running migrations', function (): void {
    schemaRegistry(schemaFixture(), schemaFixture('dedicated', SchemaScope::Central));
    expect(static fn (): array => resolve(SchemaMigrator::class)->migrateSets(['fixture/platform:tenant', 'fixture/dedicated:tenant']))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): array => resolve(SchemaMigrator::class)->migrateSets(['missing/set:tenant']))->toThrow(SchemaSetException::class, 'unknown_set')
        ->and(Schema::hasTable('schema_platform'))->toBeFalse();
});

it('rejects unknown command scopes and requires production confirmation', function (): void {
    schemaRegistry(schemaFixture());
    $this->artisan('packman:schema:migrate', ['--scope' => 'unknown'])->assertExitCode(2);
    $this->artisan('packman:schema:status', ['--scope' => 'unknown'])->assertExitCode(2);
    app()->detectEnvironment(static fn (): string => 'production');
    $this->artisan('packman:schema:migrate')->expectsConfirmation('Are you sure you want to run this command?', 'no')->assertExitCode(1);
    expect(Schema::hasTable('schema_platform'))->toBeFalse();
    $this->artisan('packman:schema:migrate', ['--force' => true])->assertSuccessful();
});

it('does not report another registered set migration as unknown', function (): void {
    $first = schemaFixture();
    $second = schemaFixture('module');
    schemaRegistry($first, $second);
    $this->migrateSchemaScope(includeModules: true);
    foreach (resolve(SchemaMigrator::class)->status(SchemaScope::Tenant) as $status) {
        expect($status->unknown)->toBe([])->and($status->pending)->toBe([]);
    }
});

it('checks all dedicated connections before running the first set', function (): void {
    config(['database.connections.schema_second' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    schemaRegistry(
        schemaFixture('dedicated', SchemaScope::Dedicated, connection: 'schema_second'),
        new SchemaSet('fixture/bad', 'budget', SchemaScope::Dedicated, schemaFixture('module')->path, 1, connection: 'testbench'),
    );
    expect(static fn (): array => resolve(SchemaMigrator::class)->migrate(SchemaScope::Dedicated))->toThrow(SchemaSetException::class, 'unsupported_driver')
        ->and(Schema::connection('schema_second')->hasTable('schema_dedicated'))->toBeFalse()
        ->and(Schema::connection('schema_second')->hasTable('migrations'))->toBeFalse();
});

it('accepts absolute POSIX drive and UNC paths without accepting relative paths', function (): void {
    foreach (['/schema', 'C:\\schema', 'C:/schema', '\\\\server\\schema'] as $path) {
        expect((new SchemaSet('fixture/path', 'tenant', SchemaScope::Tenant, $path, 1))->path)->toBe($path);
    }
    expect(static fn (): SchemaSet => new SchemaSet('fixture/path', 'tenant', SchemaScope::Tenant, 'relative/schema', 1))->toThrow(SchemaSetException::class, 'invalid_set');
});

it('deduplicates a stateless SchemaSetProvider tagged both manually and via SchemaExtensions', function (): void {
    app()->forgetInstance(SchemaSets::class);
    app()->tag([StatelessDoubleTaggedSchemaSetProvider::class], SchemaSetProvider::class);
    SchemaExtensions::registerProviders(app(), StatelessDoubleTaggedSchemaSetProvider::class);

    $ids = array_map(static fn (SchemaSet $set): string => $set->id(), resolve(SchemaSets::class)->all());

    expect($ids)->toContain('fixture/platform:tenant');
});

it('preserves distinct zero-constructor SchemaSetProvider instances carrying different property state', function (): void {
    app()->forgetInstance(SchemaSets::class);

    $first = new StatefulZeroArgSchemaSetProvider;
    $first->dataset = 'platform';

    $second = new StatefulZeroArgSchemaSetProvider;
    $second->dataset = 'module';

    app()->instance('schema.stateful.first', $first);
    app()->instance('schema.stateful.second', $second);
    app()->tag(['schema.stateful.first', 'schema.stateful.second'], SchemaSetProvider::class);

    $ids = array_map(static fn (SchemaSet $set): string => $set->id(), resolve(SchemaSets::class)->all());

    expect($ids)->toContain('fixture/platform:tenant', 'fixture/module:tenant');
});
