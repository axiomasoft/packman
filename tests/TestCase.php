<?php

declare(strict_types=1);

namespace Packman\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Override;
use Packman\Context\Contracts\ActorResolverInterface;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ActorRef;
use Packman\Context\Data\ScopeRef;
use Packman\Modules\ModulesServiceProvider;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Schema\Testing\MigratesSchemaSets;
use RuntimeException;

abstract class TestCase extends OrchestraTestCase
{
    use MigratesSchemaSets {
        migrateSchemaScope as public;
        migrateSchemaSets as public;
    }

    /** @var list<string> */
    private array $isolatedDatabasePaths = [];

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            SchemaServiceProvider::class,
            PluginsServiceProvider::class,
            ModulesServiceProvider::class,
        ];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $pgDatabase = getenv('PGSQL_DATABASE');
        $pgDatabase = is_string($pgDatabase) && $pgDatabase !== '' ? $pgDatabase : 'packman_test';

        if (! str_ends_with($pgDatabase, '_test')) {
            throw new RuntimeException('Unsafe test database: PGSQL_DATABASE must end in _test.');
        }

        $path = sys_get_temp_dir().'/packman-test-database-'.bin2hex(random_bytes(16));
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($path.'/migrations', 0700, true);
        $filesystem->makeDirectory($path.'/bootstrap/cache', 0700, true);
        $this->isolatedDatabasePaths[] = $path;
        $app->useDatabasePath($path);
        $app->useBootstrapPath($path.'/bootstrap');

        $app->singletonIf(ContextResolverInterface::class, static fn (): ContextResolverInterface => new readonly class implements ContextResolverInterface
        {
            public function current(): ContextScopeInterface
            {
                return new ScopeRef('acct-test');
            }
        });
        $app->singletonIf(ActorResolverInterface::class, static fn (): ActorResolverInterface => new readonly class implements ActorResolverInterface
        {
            public function current(): ActorRef
            {
                return new ActorRef('system');
            }
        });

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $host = getenv('PGSQL_HOST');
        $port = getenv('PGSQL_PORT');
        $database = getenv('PGSQL_DATABASE');
        $username = getenv('PGSQL_USERNAME');
        $password = getenv('PGSQL_PASSWORD');

        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'timezone' => 'UTC',
            'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
            'port' => is_string($port) && $port !== '' ? $port : '54329',
            'database' => is_string($database) && $database !== '' ? $database : 'packman_test',
            'username' => is_string($username) && $username !== '' ? $username : 'skm',
            'password' => is_string($password) && $password !== '' ? $password : 'skm_dev',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ]);
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $filesystem = new Filesystem;

            foreach ($this->isolatedDatabasePaths as $path) {
                $filesystem->deleteDirectory($path);
            }

            $this->isolatedDatabasePaths = [];
        }
    }
}
