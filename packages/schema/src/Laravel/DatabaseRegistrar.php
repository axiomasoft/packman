<?php

declare(strict_types=1);

namespace Packman\Schema\Laravel;

use Closure;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Migration\ModuleMigrationBoundary;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Data\Compiler\TableNames;
use Packman\Schema\Exceptions\SchemaSetException;
use Packman\Schema\Internal\Commands\MigrateSchemaCommand;
use Packman\Schema\Internal\Commands\SchemaStatusCommand;
use Packman\Schema\Internal\Compiler\ScopedOwnershipRegistry;
use Packman\Schema\Internal\Migration\DefaultSchemaMigrator;
use Packman\Schema\Internal\Migration\TaggedSchemaSets;
use ReflectionClass;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DatabaseRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $app->singletonIf(SchemaOwnership::class, static fn (): SchemaOwnership => new ScopedOwnershipRegistry);

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
                ownership: SchemaExtensions::ownershipFor($app->make(SchemaOwnership::class), $prefix),
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

            $db = $app->make('db');

            return new DefaultSchemaMigrator(
                sets: $app->make(SchemaSets::class),
                migrator: $app->make('migrator'),
                db: $db,
                moduleMigrations: $moduleMigrations,
                prerequisiteSetId: $prerequisiteSetId,
                isActiveHost: static fn (): bool => Container::getInstance() === $app
                    && Facade::getFacadeApplication() === $app
                    && DB::getFacadeRoot() === $db,
            );
        });
    }

    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            $app->make(Dispatcher::class)->listen(ArtisanStarting::class, self::registerCommands(...));
        }
    }

    private static function registerCommands(ArtisanStarting $event): void
    {
        $event->artisan->resolveCommands([MigrateSchemaCommand::class, SchemaStatusCommand::class]);
    }

    private static function schemaSets(Application $app): SchemaSets
    {
        /** @var array<class-string, true> $seenStateless */
        $seenStateless = [];
        /** @var array<int, true> $seenObjects */
        $seenObjects = [];
        $providers = [];

        foreach ($app->tagged(SchemaSetProvider::class) as $provider) {
            if (! $provider instanceof SchemaSetProvider) {
                throw SchemaSetException::invalidSet('SchemaSetProvider tag');
            }

            $objectId = spl_object_id($provider);

            if (isset($seenObjects[$objectId])) {
                continue;
            }

            $reflection = new ReflectionClass($provider);
            $constructor = $reflection->getConstructor();
            $isStatelessNamed = ! $reflection->isAnonymous()
                && ($constructor === null || $constructor->getNumberOfParameters() === 0)
                && (array) $provider === [];

            if ($isStatelessNamed && isset($seenStateless[$provider::class])) {
                continue;
            }

            $seenObjects[$objectId] = true;

            if ($isStatelessNamed) {
                $seenStateless[$provider::class] = true;
            }

            $providers[] = $provider;
        }

        return new TaggedSchemaSets($providers);
    }
}
