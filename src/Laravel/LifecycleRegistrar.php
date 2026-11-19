<?php

declare(strict_types=1);

namespace Packman\Modules\Laravel;

use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Context\Contracts\ActorResolverInterface;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Modules\Contracts\Definition\ModuleDiscovery;
use Packman\Modules\Contracts\Lifecycle\ModuleLifecycle;
use Packman\Modules\Contracts\Lifecycle\RecordsRegistrar;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Contracts\Registry\ModuleStateReader;
use Packman\Modules\Internal\Lifecycle\DatabaseModuleLifecycle;
use Packman\Modules\Internal\Lifecycle\DatabaseModuleStateReader;
use Packman\Modules\Internal\Lifecycle\DatabaseRecordsRegistrar;
use Packman\Modules\Internal\Lifecycle\ModulesMigrationBoundary;
use Packman\Modules\Internal\Schema\ModulesSchemaSets;
use Packman\Modules\Internal\Support\ModulesOptions;
use Packman\Schema\Contracts\Migration\ModuleMigrationBoundary;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Exceptions\SchemaSetException;
use Psr\Clock\ClockInterface;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class LifecycleRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        if (interface_exists(SchemaSetProvider::class)) {
            $app->tag([ModulesSchemaSets::class], SchemaSetProvider::class);
        }

        if (interface_exists(ModuleMigrationBoundary::class)) {
            $app->singleton(ModuleMigrationBoundary::class, ModulesMigrationBoundary::class);
        }

        $app->singletonIf(ModuleStateReader::class, static function (Application $app): DatabaseModuleStateReader {
            $options = $app->make(ModulesOptions::class);

            return new DatabaseModuleStateReader(
                connections: $app->make(ConnectionResolverInterface::class),
                connection: $options->connection,
                modulesTable: $options->modulesTable,
                lifecycleLogTable: $options->lifecycleLogTable,
            );
        });

        $app->singleton(RecordsRegistrar::class, static function (Application $app): DatabaseRecordsRegistrar {
            $options = $app->make(ModulesOptions::class);

            return new DatabaseRecordsRegistrar(
                connections: $app->make(ConnectionResolverInterface::class),
                clock: $app->make(ClockInterface::class),
                connection: $options->connection,
                recordsTable: $options->recordsTable,
            );
        });

        // Keep the assembled wiring overridable for isolated lifecycle tests.
        $app->singleton('modules.wiring', fn (Application $app): array => $this->moduleWiring(
            sets: $app->make(SchemaSets::class),
            installedJsonPath: $app->basePath('vendor/composer/installed.json'),
            packageTypes: $app->make(ModulesOptions::class)->packageTypes,
        ));

        $app->singleton(ModuleLifecycle::class, static fn (Application $app): DatabaseModuleLifecycle => self::moduleLifecycle($app));
    }

    /**
     * Module versions come from Composer; schema paths come from their owners' registered sets.
     *
     * @param  list<string>  $packageTypes
     * @return array{codeVersions: array<string, string>, migrationPaths: array<string, string>}
     */
    private function moduleWiring(SchemaSets $sets, string $installedJsonPath, array $packageTypes): array
    {
        $codeVersions = [];
        $migrationPaths = [];

        foreach ($sets->all() as $set) {
            if ($set->scope === SchemaScope::Tenant && $set->lifecycle === SchemaLifecycle::Module) {
                $moduleSet = $sets->moduleSet($set->package);

                if ($moduleSet === null) {
                    throw SchemaSetException::invalidSet($set->id());
                }

                $migrationPaths[$set->package] = $moduleSet->path;
            }
        }

        if (! is_file($installedJsonPath)) {
            return ['codeVersions' => $codeVersions, 'migrationPaths' => $migrationPaths];
        }

        // Fail closed like ModuleDiscovery: a corrupt manifest must not silently reset every code version to 0.0.0.
        /** @var mixed $data */
        $data = json_decode((string) file_get_contents($installedJsonPath), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            return ['codeVersions' => $codeVersions, 'migrationPaths' => $migrationPaths];
        }

        /** @var list<mixed> $packages */
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $type = $package['type'] ?? null;

            if (! is_string($type) || ! in_array($type, $packageTypes, true)) {
                continue;
            }

            $name = $package['name'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            if (is_string($package['version'] ?? null)) {
                $codeVersions[$name] = $package['version'];
            }
        }

        return ['codeVersions' => $codeVersions, 'migrationPaths' => $migrationPaths];
    }

    private static function moduleLifecycle(Application $app): DatabaseModuleLifecycle
    {
        /** @var array{codeVersions: array<string, string>, migrationPaths: array<string, string>} $wiring */
        $wiring = $app->make('modules.wiring');
        $options = $app->make(ModulesOptions::class);
        $actorResolver = $app->bound(ActorResolverInterface::class)
            ? $app->make(ActorResolverInterface::class)
            : null;

        return new DatabaseModuleLifecycle(
            registry: $app->make(ModuleRegistry::class),
            discovery: $app->make(ModuleDiscovery::class),
            records: $app->make(RecordsRegistrar::class),
            container: $app,
            tenantContextResolver: $app->make(ContextResolverInterface::class),
            connections: $app->make(ConnectionResolverInterface::class),
            events: $app->make(EventDispatcher::class),
            clock: $app->make(ClockInterface::class),
            codeVersions: $wiring['codeVersions'],
            migrationPaths: $wiring['migrationPaths'],
            connection: $options->connection,
            actorResolver: $actorResolver,
            lockKey: $options->lockKey,
            modulesTable: $options->modulesTable,
            recordsTable: $options->recordsTable,
            lifecycleLogTable: $options->lifecycleLogTable,
        );
    }
}
