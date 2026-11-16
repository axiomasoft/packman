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
use Packman\Testkit\Fakes\Models\TestUser;
use Packman\Testkit\Http\Problems\ProblemProbeRoutes;
use Packman\Testkit\PackageTestCase;
use Packman\Testkit\Schema\Database\AuditTable;
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
}
