<?php

declare(strict_types=1);

namespace Packman\Schema\Tests\Unit;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use InvalidArgumentException;
use LogicException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Packman\Schema\Contracts\SchemaMigrator;
use Packman\Schema\Contracts\SchemaOwnership;
use Packman\Schema\Contracts\SchemaSetProvider;
use Packman\Schema\Contracts\SchemaSets;
use Packman\Schema\Data\SchemaSet;
use Packman\Schema\Data\TableNames;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Exceptions\SchemaSetException;
use Packman\Schema\Internal\DefaultSchemaMigrator;
use Packman\Schema\Internal\TaggedSchemaSets;
use Packman\Schema\Laravel\DatabaseRegistrar;
use Packman\Schema\Testing\MigratesSchemaSets;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SchemaStatusContractTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_status_reports_all_sets_in_identity_order_and_reindexes_partial_journal_results(): void
    {
        $z = new SchemaSet(package: 'zz/package', name: 'tenant', scope: SchemaScope::Tenant, path: '/schema/z', version: 1);
        $a = new SchemaSet(package: 'aa/package', name: 'tenant', scope: SchemaScope::Tenant, path: '/schema/a', version: 1);
        $first = '2026_10_01_010001_first';
        $second = '2026_10_01_010002_second';
        $module = '2026_10_01_020001_module';
        $unknown = '2026_10_02_010003_unknown';
        $foreign = 'prefix_2026_10_02_010004_not_a_baseline';
        $sets = Mockery::mock(SchemaSets::class);
        $sets->shouldReceive('forScope')->once()->with(SchemaScope::Tenant, true)->andReturn([$z, $a]);
        $sets->shouldReceive('all')->once()->andReturn([$z, $a]);
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('repositoryExists')->once()->andReturnTrue();
        $repository->shouldReceive('getRan')->once()->andReturn([$second, $unknown, $foreign]);
        $migrator = Mockery::mock(Migrator::class);
        $migrator->shouldReceive('getRepository')->once()->andReturn($repository);
        $migrator->shouldReceive('getMigrationFiles')->once()->with(['/schema/z', '/schema/a'])->andReturn([$first => 'first.php', $second => 'second.php', $module => 'module.php']);
        $migrator->shouldReceive('getMigrationFiles')->once()->with(['/schema/z'])->andReturn([$first => 'first.php', $second => 'second.php']);
        $migrator->shouldReceive('getMigrationFiles')->once()->with(['/schema/a'])->andReturn([$module => 'module.php']);
        $migrator->shouldReceive('usingConnection')->once()->with('isolated', Mockery::type(Closure::class))->andReturnUsing(static fn (string $_name, Closure $callback) => $callback());
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->twice()->andReturn('pgsql');
        $db = Mockery::mock(DatabaseManager::class);
        $db->shouldReceive('connection')->twice()->with('isolated')->andReturn($connection);
        $statuses = (new DefaultSchemaMigrator(sets: $sets, migrator: $migrator, db: $db))->status(scope: SchemaScope::Tenant, connection: 'isolated');
        self::assertCount(2, $statuses);
        self::assertSame($a, $statuses[0]->set);
        self::assertSame([], $statuses[0]->applied);
        self::assertSame([$module], $statuses[0]->pending);
        self::assertSame([], $statuses[0]->unknown);
        self::assertSame($z, $statuses[1]->set);
        self::assertSame([$second], $statuses[1]->applied);
        self::assertSame([$first], $statuses[1]->pending);
        self::assertSame([$unknown], $statuses[1]->unknown);
    }

    public function test_dedicated_overrides_are_refused_before_registry_or_database_access(): void
    {
        $sets = Mockery::mock(SchemaSets::class);
        $migrator = new DefaultSchemaMigrator(sets: $sets, migrator: Mockery::mock(Migrator::class), db: Mockery::mock(DatabaseManager::class));
        foreach (['migrate', 'status'] as $method) {
            try {
                $migrator->{$method}(scope: SchemaScope::Dedicated, connection: 'ambient');
                self::fail('Dedicated override was admitted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Dedicated schema sets run on their own connections.', $exception->getMessage());
            }
        }
        $sets->shouldNotHaveReceived('forScope');
    }

    public function test_migration_default_does_not_silently_activate_module_schemas(): void
    {
        $sets = Mockery::mock(SchemaSets::class);
        $sets->shouldReceive('forScope')->once()->with(SchemaScope::Tenant, false)->andReturn([]);
        $migrator = new DefaultSchemaMigrator(sets: $sets, migrator: Mockery::mock(Migrator::class), db: Mockery::mock(DatabaseManager::class));
        self::assertSame([], $migrator->migrate(scope: SchemaScope::Tenant));
    }

    public function test_invalid_set_diagnostic_retains_package_and_set_name(): void
    {
        $this->expectException(SchemaSetException::class);
        $this->expectExceptionMessage('invalid_set: Invalid schema set [fixture/package:bad-name].');
        new SchemaSet(package: 'fixture/package', name: 'bad-name', scope: SchemaScope::Tenant, path: '/schema', version: 0);
    }

    public function test_module_lookup_requires_the_exact_package_scope_and_lifecycle(): void
    {
        $platform = new SchemaSet(package: 'fixture/package', name: 'platform', scope: SchemaScope::Tenant, path: __DIR__, version: 1);
        $other = new SchemaSet(package: 'fixture/other', name: 'tenant', scope: SchemaScope::Tenant, path: __DIR__, version: 1, lifecycle: SchemaLifecycle::Module);
        $provider = $this->createStub(SchemaSetProvider::class);
        $provider->method('sets')->willReturn([$other, $platform]);
        $sets = new TaggedSchemaSets(providers: [$provider]);
        self::assertSame([$other, $platform], $sets->all());
        self::assertSame([$platform], $sets->forScope(scope: SchemaScope::Tenant, includeModules: false));
        self::assertSame([$other, $platform], $sets->forScope(scope: SchemaScope::Tenant, includeModules: true));
        self::assertNull($sets->moduleSet(package: 'fixture/package'));
        self::assertNull($sets->moduleSet(package: 'fixture/missing'));
        self::assertSame($other, $sets->moduleSet(package: 'fixture/other'));
        self::assertSame($platform, $sets->get(id: 'fixture/package:platform'));
    }

    public function test_invalid_tagged_provider_is_refused_before_building_the_registry(): void
    {
        $factory = null;
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('singletonIf')->once()->with(SchemaOwnership::class, Mockery::type(Closure::class));
        $app->shouldReceive('singleton')->once()->with(TableNames::class, Mockery::type(Closure::class));
        $app->shouldReceive('singleton')->once()->with(SchemaSets::class, Mockery::type(Closure::class))->andReturnUsing(function (string $_class, Closure $callback) use (&$factory): void {
            $factory = $callback;
        });
        $app->shouldReceive('singleton')->once()->with(SchemaMigrator::class, Mockery::type(Closure::class));
        $app->shouldReceive('tagged')->once()->with(SchemaSetProvider::class)->andReturn([new stdClass]);
        (new DatabaseRegistrar)->register(app: $app);
        self::assertInstanceOf(Closure::class, $factory);
        $this->expectException(SchemaSetException::class);
        $this->expectExceptionMessage('invalid_set: Invalid schema set [SchemaSetProvider tag].');
        $factory($app);
    }

    public function test_testbench_helper_defaults_and_explicit_selection_reach_the_same_schema_migrator(): void
    {
        $app = new Container;
        $migrator = Mockery::mock(SchemaMigrator::class);
        $migrator->shouldReceive('migrate')->once()->with(SchemaScope::Tenant, null, true)->andReturn([]);
        $migrator->shouldReceive('migrateSets')->once()->with(['fixture/package:tenant'], 'isolated')->andReturn([]);
        $app->instance(SchemaMigrator::class, $migrator);
        $helper = new class extends SchemaMigrationHelperFixture
        {
            public function scope(): void
            {
                $this->migrateSchemaScope();
            }

            public function selected(): void
            {
                $this->migrateSchemaSets(['fixture/package:tenant'], 'isolated');
            }
        };
        $helper->app = $app;
        $helper->scope();
        $helper->selected();
        self::assertSame($app, $helper->app);
        $helper->app = null;
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Boot the test application before migrating schema sets.');
        $helper->scope();
    }
}

abstract class SchemaMigrationHelperFixture
{
    use MigratesSchemaSets;

    public ?Container $app = null;
}
