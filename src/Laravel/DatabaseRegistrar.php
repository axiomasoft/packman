<?php

declare(strict_types=1);

namespace Packman\Schema\Laravel;

use Closure;
use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Schema\Contracts\ModuleMigrationBoundary;
use Packman\Schema\Contracts\SchemaMigrator;
use Packman\Schema\Contracts\SchemaOwnership;
use Packman\Schema\Contracts\SchemaSetProvider;
use Packman\Schema\Contracts\SchemaSets;
use Packman\Schema\Data\TableNames;
use Packman\Schema\Exceptions\SchemaSetException;
use Packman\Schema\Internal\Commands\MigrateSchemaCommand;
use Packman\Schema\Internal\Commands\SchemaStatusCommand;
use Packman\Schema\Internal\DefaultSchemaMigrator;
use Packman\Schema\Internal\SchemaOwnershipRegistry;
use Packman\Schema\Internal\TaggedSchemaSets;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DatabaseRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $app->singletonIf(SchemaOwnership::class, static fn (): SchemaOwnership => new SchemaOwnershipRegistry);

        $app->singleton(TableNames::class, static function (Application $app): TableNames {
            $prefix = 'pmn_';
            $connection = null;

            if ($app->bound(Repository::class)) {
                $config = $app->make(Repository::class);
                $configuredPrefix = $config->get('packman.schema.table_prefix');

                if (is_string($configuredPrefix)) {
                    $prefix = $configuredPrefix;
                }

                $configuredConnection = $config->get('packman.schema.connection');
                $connection = is_string($configuredConnection) && $configuredConnection !== '' ? $configuredConnection : null;
            }

            return new TableNames(
                prefix: $prefix,
                ownership: $app->make(SchemaOwnership::class),
                connections: $app->make('db'),
                connection: $connection,
            );
        });

        $app->singleton(SchemaSets::class, static fn (Application $app): SchemaSets => self::schemaSets($app));

        $app->singleton(SchemaMigrator::class, static function (Application $app): SchemaMigrator {
            $moduleMigrations = null;

            if ($app->bound(ModuleMigrationBoundary::class)) {
                $moduleMigrations = static function (Connection $db, string $name, array $owners, Closure $run) use ($app): void {
                    /** @var array<string, string> $owners */
                    $app->make(ModuleMigrationBoundary::class)->run($db, $name, $owners, $run);
                };
            }

            $prerequisiteSetId = 'axiomasoft/packman-modules:tenant';

            if ($app->bound(Repository::class)) {
                $configured = $app->make(Repository::class)->get('packman.schema.module_prerequisite_set', $prerequisiteSetId);
                $prerequisiteSetId = is_string($configured) && $configured !== '' ? $configured : null;
            }

            return new DefaultSchemaMigrator(
                sets: $app->make(SchemaSets::class),
                migrator: $app->make('migrator'),
                db: $app->make('db'),
                moduleMigrations: $moduleMigrations,
                prerequisiteSetId: $prerequisiteSetId,
            );
        });
    }

    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            Artisan::starting(self::registerCommands(...));
        }
    }

    private static function registerCommands(Artisan $artisan): void
    {
        $artisan->resolveCommands([MigrateSchemaCommand::class, SchemaStatusCommand::class]);
    }

    private static function schemaSets(Application $app): SchemaSets
    {
        $providers = [];
        foreach ($app->tagged(SchemaSetProvider::class) as $provider) {
            if (! $provider instanceof SchemaSetProvider) {
                throw SchemaSetException::invalidSet('SchemaSetProvider tag');
            }
            $providers[] = $provider;
        }

        return new TaggedSchemaSets($providers);
    }
}
