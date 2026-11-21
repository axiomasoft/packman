<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Packman\Doctor\Checks\SchemaStatusCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;

/**
 * @param  list<string>  $ran
 */
function schemaStatusRepository(bool $exists, array $ran = [], bool $explode = false): MigrationRepositoryInterface
{
    return new class($exists, $ran, $explode) implements MigrationRepositoryInterface
    {
        /** @param list<string> $ran */
        public function __construct(private bool $exists, private array $ran, private bool $explode) {}

        public function getRan()
        {
            if ($this->explode) {
                throw new RuntimeException('repository unreadable');
            }

            return $this->ran;
        }

        public function getMigrations($steps)
        {
            return [];
        }

        public function getMigrationsByBatch($batch)
        {
            return [];
        }

        public function getLast()
        {
            return [];
        }

        public function getMigrationBatches()
        {
            return [];
        }

        public function log($file, $batch): void {}

        public function delete($migration): void {}

        public function getNextBatchNumber()
        {
            return 1;
        }

        public function createRepository(): void {}

        public function repositoryExists()
        {
            return $this->exists;
        }

        public function deleteRepository(): void {}

        public function setSource($name): void {}
    };
}

function schemaStatusResolver(bool $connects): ConnectionResolverInterface
{
    return new class($connects) implements ConnectionResolverInterface
    {
        public function __construct(private bool $connects) {}

        public function connection($name = null)
        {
            if (! $this->connects) {
                throw new RuntimeException('database down');
            }

            return new Connection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
        }

        public function getDefaultConnection()
        {
            return 'sqlite';
        }

        public function setDefaultConnection($name): void {}
    };
}

it('skips schema inspection when no connection resolver is bound', function (): void {
    $finding = (new SchemaStatusCheck(new Container))->run(new DoctorContext)[0];

    expect($finding->status)->toBe(CheckStatus::Skipped)
        ->and($finding->code)->toBe('core.schema.skipped');
});

it('reports a failed connection without inspecting migrations', function (): void {
    $container = new Container;
    $container->instance(ConnectionResolverInterface::class, schemaStatusResolver(false));

    $finding = (new SchemaStatusCheck($container))->run(new DoctorContext)[0];

    expect($finding->status)->toBe(CheckStatus::Error)
        ->and($finding->code)->toBe('core.database.connection_failed');
});

it('includes the application database/migrations directory and a single migration file', function (): void {
    $root = sys_get_temp_dir().'/packman-dbpath-'.bin2hex(random_bytes(4));
    mkdir($root.'/migrations', recursive: true);
    file_put_contents($root.'/migrations/2024_01_01_000000_create_orders.php', '<?php');
    $single = $root.'/one.php';
    file_put_contents($single, '<?php');

    $container = new Container;
    $container->instance(ConnectionResolverInterface::class, schemaStatusResolver(true));
    $container->instance('migration.repository', schemaStatusRepository(true, []));
    $container->instance('migrator', new class($single)
    {
        public function __construct(private string $file) {}

        /** @return list<string> */
        public function paths(): array
        {
            return [$this->file];
        }
    });
    $container->instance('path.database', $root);

    try {
        $finding = (new SchemaStatusCheck($container))->run(new DoctorContext)[0];

        expect($finding->code)->toBe('core.schema.migrations_pending')
            ->and($finding->evidence['count'])->toBe(2);
    } finally {
        unlink($root.'/migrations/2024_01_01_000000_create_orders.php');
        unlink($single);
        rmdir($root.'/migrations');
        rmdir($root);
    }
});

it('reports a live connection when migrations are not configured', function (): void {
    $container = new Container;
    $container->instance(ConnectionResolverInterface::class, schemaStatusResolver(true));

    $finding = (new SchemaStatusCheck($container))->run(new DoctorContext)[0];

    expect($finding->status)->toBe(CheckStatus::Ok)
        ->and($finding->code)->toBe('core.database.connected');
});

it('treats migration names as exact files and reports an unreadable repository', function (): void {
    $directory = sys_get_temp_dir().'/packman-migrations-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/2020_01_01_000000_create_users.php', '<?php');
    file_put_contents($directory.'/2024_01_01_000000_create_users.php', '<?php');

    $container = new Container;
    $container->instance(ConnectionResolverInterface::class, schemaStatusResolver(true));
    $container->instance('migration.repository', schemaStatusRepository(true, ['2020_01_01_000000_create_users']));
    $container->instance('migrator', new class($directory)
    {
        public function __construct(private string $directory) {}

        /** @return list<string> */
        public function paths(): array
        {
            return [$this->directory];
        }
    });

    try {
        $pending = (new SchemaStatusCheck($container))->run(new DoctorContext)[0];

        expect($pending->code)->toBe('core.schema.migrations_pending')
            ->and($pending->evidence['first'])->toBe('2024_01_01_000000_create_users')
            ->and($pending->evidence['count'])->toBe(1)
            ->and($pending->status)->toBe(CheckStatus::Warning);

        $container->instance('migration.repository', schemaStatusRepository(true, explode: true));
        $failed = (new SchemaStatusCheck($container))->run(new DoctorContext(production: true))[0];

        expect($failed->code)->toBe('core.schema.inspection_failed')
            ->and($failed->status)->toBe(CheckStatus::Error);
    } finally {
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});
