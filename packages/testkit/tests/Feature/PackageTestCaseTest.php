<?php

declare(strict_types=1);

namespace Packman\Testkit\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Override;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\PackmanServiceProvider;
use Packman\Testkit\Concerns\Application\EnablesModules;
use Packman\Testkit\Concerns\Database\RefreshesDatabaseForSchemaVariant;
use Packman\Testkit\Concerns\Database\WithoutDatabaseSchema;
use Packman\Testkit\Exceptions\Database\UnsafeTestDatabaseException;
use Packman\Testkit\Fakes\Models\TestUser;
use Packman\Testkit\Http\Problems\ProblemProbeRoutes;
use Packman\Testkit\PackageTestCase;
use Packman\Testkit\Schema\Database\AuditTable;
use Packman\Testkit\Support\Database\DatabaseLaneEnvironment;
use PDO;

final class PackageTestCaseTest extends PackageTestCase
{
    use EnablesModules;
    use RefreshesDatabaseForSchemaVariant;
    use WithoutDatabaseSchema;

    #[Override]
    protected function getPackageProviders($app): array
    {
        return [PackmanServiceProvider::class];
    }

    public function test_isolated_sqlite_paths_problem_probe_routes_and_audit_table_work(): void
    {
        $sqlitePath = $this->createIsolatedSqlitePath();
        $pdo = new PDO('sqlite:'.$sqlitePath);
        $pdo->exec('CREATE TABLE test_audit (id INTEGER PRIMARY KEY, slug TEXT NOT NULL)');

        $app = $this->app;
        assert($app instanceof Application);

        $audit = new AuditTable($pdo);
        self::assertTrue($audit->exists('test_audit'));
        self::assertSame(['id', 'slug'], $audit->columnNames('test_audit'));

        $registry = $app->make(ProblemRegistry::class);
        $this->assertAllErrorCodesRegistered($registry, CoreErrorCode::class);
        $this->assertCodedExceptionRegistered($registry, new class('bad config') extends CodedException
        {
            public function errorDescriptor(): ErrorDescriptor
            {
                return new ErrorDescriptor(
                    code: CoreErrorCode::InvalidScopePayload,
                    category: ErrorCategory::Validation,
                    retryable: false,
                );
            }
        });

        $router = $app->make(Router::class);
        ProblemProbeRoutes::register($router, $registry);

        $this->getJson('/_packman/problems')
            ->assertOk()
            ->assertJsonStructure(['problems']);

        $expectedStatus = $registry->get(CoreErrorCode::InvalidConfiguration)->defaultStatus;
        $this->get('/_packman/problems/'.CoreErrorCode::InvalidConfiguration->value())
            ->assertStatus($expectedStatus)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->enablePackmanModules(['axiomasoft/sample']);
        self::assertSame(['axiomasoft/sample'], config('packman-modules.enabled'));
        $this->assertConnectionGuarded('sqlite', ':memory:');
        $user = new TestUser(['name' => 'Test User', 'email' => 'test@example.com']);
        self::assertSame('Test User', $user->name);

        DB::connection('sqlite')->statement('CREATE TABLE tmp_drop_check (id INTEGER)');
        $this->dropAllTablesSafely('sqlite');
        self::assertFalse($app->make('db')->connection('sqlite')->getSchemaBuilder()->hasTable('tmp_drop_check'));
    }

    public function test_destructive_helpers_validate_actual_resolved_connection_and_opt_in(): void
    {
        config()->set('database.connections.module_race', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 54329,
            'database' => 'customer_data',
            'username' => 'skm',
            'password' => 'skm_dev',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ]);
        config()->set('database.connections.sqlite_real_file', [
            'driver' => 'sqlite',
            'database' => '/tmp/customer_data.sqlite',
            'prefix' => '',
        ]);
        config()->set('database.connections.sqlite_test_file', [
            'driver' => 'sqlite',
            'database' => sys_get_temp_dir().'/packman_file_test',
            'prefix' => '',
        ]);

        // Even with PGSQL_DATABASE=packman_test and PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1,
        // non-default connections pointing to real/non-_test DBs must be refused.
        $refusedRaceReset = false;

        try {
            $this->resetPgsqlPublicSchema('module_race');
        } catch (UnsafeTestDatabaseException) {
            $refusedRaceReset = true;
        }
        self::assertTrue($refusedRaceReset);

        $refusedRaceDrop = false;

        try {
            $this->dropAllTablesSafely('module_race');
        } catch (UnsafeTestDatabaseException) {
            $refusedRaceDrop = true;
        }
        self::assertTrue($refusedRaceDrop);

        $refusedSqliteFile = false;

        try {
            $this->dropAllTablesSafely('sqlite_real_file');
        } catch (UnsafeTestDatabaseException) {
            $refusedSqliteFile = true;
        }
        self::assertTrue($refusedSqliteFile);

        // Non-pgsql driver refused by resetPgsqlPublicSchema().
        $refusedWrongDriver = false;

        try {
            $this->resetPgsqlPublicSchema('sqlite');
        } catch (UnsafeTestDatabaseException) {
            $refusedWrongDriver = true;
        }
        self::assertTrue($refusedWrongDriver);

        // Destructive reset on non-:memory: *_test DB requires PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1,
        // while :memory: sqlite remains permitted without opt-in.
        $previous = getenv('PACKMAN_TEST_DESTRUCTIVE_OPT_IN');
        $previousEnv = $_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] ?? null;
        $previousServer = $_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] ?? null;

        putenv('PACKMAN_TEST_DESTRUCTIVE_OPT_IN');
        unset($_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'], $_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN']);

        try {
            $this->dropAllTablesSafely('sqlite');

            $refusedWithoutOptIn = false;

            try {
                $this->resetPgsqlPublicSchema('pgsql');
            } catch (UnsafeTestDatabaseException) {
                $refusedWithoutOptIn = true;
            }
            self::assertTrue($refusedWithoutOptIn);

            $refusedFileWithoutOptIn = false;

            try {
                $this->dropAllTablesSafely('sqlite_test_file');
            } catch (UnsafeTestDatabaseException) {
                $refusedFileWithoutOptIn = true;
            }
            self::assertTrue($refusedFileWithoutOptIn);
        } finally {
            putenv($previous === false ? 'PACKMAN_TEST_DESTRUCTIVE_OPT_IN' : "PACKMAN_TEST_DESTRUCTIVE_OPT_IN={$previous}");

            if ($previousEnv === null) {
                unset($_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN']);
            } else {
                $_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN']);
            } else {
                $_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] = $previousServer;
            }
        }
    }

    public function test_destructive_helpers_reject_unsafe_read_write_and_direct_sub_connections_even_with_opt_in(): void
    {
        self::assertTrue(DatabaseLaneEnvironment::destructiveOptIn());

        $previousPgsql = config('database.connections.pgsql');

        try {
            DB::purge('pgsql');
            DB::purge('pgsql::read');
            DB::purge('pgsql::write');
            DB::purge('pgsql::direct');

            // 1) Primary config targets safe audit_test, but ::read targets customer_data.
            config()->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 54329,
                'database' => 'audit_test',
                'username' => 'skm',
                'password' => 'skm_dev',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'read' => [
                    'host' => '127.0.0.1',
                    'port' => 54329,
                    'database' => 'customer_data',
                    'username' => 'skm',
                ],
                'write' => [
                    'host' => '127.0.0.1',
                    'port' => 54329,
                    'database' => 'audit_test',
                    'username' => 'skm',
                ],
            ]);

            foreach (['pgsql::read', 'pgsql'] as $target) {
                $refused = false;

                try {
                    $this->resetPgsqlPublicSchema($target);
                } catch (UnsafeTestDatabaseException $exception) {
                    $refused = str_contains($exception->getMessage(), 'customer_data');
                }
                self::assertTrue($refused, "Expected UnsafeTestDatabaseException for [{$target}] read -> customer_data");

                $refusedDrop = false;

                try {
                    $this->dropAllTablesSafely($target);
                } catch (UnsafeTestDatabaseException $exception) {
                    $refusedDrop = str_contains($exception->getMessage(), 'customer_data');
                }
                self::assertTrue($refusedDrop, "Expected dropAllTablesSafely to refuse [{$target}] read -> customer_data");
            }

            // 2) Primary config targets safe audit_test, but ::direct targets customer_data.
            DB::purge('pgsql');
            DB::purge('pgsql::direct');
            config()->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 54329,
                'database' => 'audit_test',
                'username' => 'skm',
                'password' => 'skm_dev',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'direct' => [
                    'host' => '127.0.0.1',
                    'port' => 54329,
                    'database' => 'customer_data',
                    'username' => 'skm',
                ],
            ]);

            foreach (['pgsql::direct', 'pgsql'] as $target) {
                $refusedDirect = false;

                try {
                    $this->resetPgsqlPublicSchema($target);
                } catch (UnsafeTestDatabaseException $exception) {
                    $refusedDirect = str_contains($exception->getMessage(), 'customer_data');
                }
                self::assertTrue($refusedDirect, "Expected UnsafeTestDatabaseException for [{$target}] direct -> customer_data");

                $refusedDirectDrop = false;

                try {
                    $this->dropAllTablesSafely($target);
                } catch (UnsafeTestDatabaseException $exception) {
                    $refusedDirectDrop = str_contains($exception->getMessage(), 'customer_data');
                }
                self::assertTrue($refusedDirectDrop, "Expected dropAllTablesSafely to refuse [{$target}] direct -> customer_data");
            }

            // 3) Nested write config (even without read) targeting customer_data must be rejected.
            DB::purge('pgsql');
            DB::purge('pgsql::write');
            config()->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 54329,
                'database' => 'audit_test',
                'username' => 'skm',
                'password' => 'skm_dev',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'write' => [
                    'database' => 'customer_data',
                ],
            ]);

            $refusedWrite = false;

            try {
                $this->resetPgsqlPublicSchema('pgsql::write');
            } catch (UnsafeTestDatabaseException $exception) {
                $refusedWrite = str_contains($exception->getMessage(), 'customer_data');
            }
            self::assertTrue($refusedWrite);

            // 4) List of read replicas where one entry targets customer_data must always be refused.
            DB::purge('pgsql');
            DB::purge('pgsql::read');
            config()->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 54329,
                'database' => 'audit_test',
                'username' => 'skm',
                'password' => 'skm_dev',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'read' => [
                    ['host' => '127.0.0.1', 'database' => 'audit_read_test'],
                    ['host' => '127.0.0.1', 'database' => 'customer_data'],
                ],
            ]);

            $refusedReplicaList = false;

            try {
                $this->resetPgsqlPublicSchema('pgsql::read');
            } catch (UnsafeTestDatabaseException $exception) {
                $refusedReplicaList = str_contains($exception->getMessage(), 'customer_data');
            }
            self::assertTrue($refusedReplicaList);
        } finally {
            DB::purge('pgsql');
            DB::purge('pgsql::read');
            DB::purge('pgsql::write');
            DB::purge('pgsql::direct');
            config()->set('database.connections.pgsql', $previousPgsql);
        }
    }
}
