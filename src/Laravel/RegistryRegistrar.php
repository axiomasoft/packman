<?php

declare(strict_types=1);

namespace Packman\Modules\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Modules\Contracts\Compiler\FieldCastRegistry;
use Packman\Modules\Contracts\Compiler\ManifestSectionCompiler;
use Packman\Modules\Contracts\Compiler\ModuleCompilation;
use Packman\Modules\Contracts\Definition\ModuleDiscovery as ModuleDiscoveryContract;
use Packman\Modules\Contracts\Entities\EntityRegistry;
use Packman\Modules\Contracts\Lifecycle\ModuleActivationGate;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Contracts\Registry\ModuleRegistryCache;
use Packman\Modules\Contracts\Registry\ModuleStateReader;
use Packman\Modules\Contracts\Registry\RegistryIntrospection;
use Packman\Modules\Internal\Compiler\FieldCastMap;
use Packman\Modules\Internal\Compiler\RegistryCompiler;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Modules\Internal\Entities\InMemoryEntityRegistry;
use Packman\Modules\Internal\Gate\AllowAllActivationGate;
use Packman\Modules\Internal\Registry\CompiledModuleRegistry;
use Packman\Modules\Internal\Registry\ReadRegistryIntrospection;
use Packman\Modules\Internal\Registry\RepositoryModuleRegistryCache;
use Packman\Modules\Internal\Support\ModulesOptions;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class RegistryRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    private const string INSTALLED_JSON = 'vendor/composer/installed.json';

    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(ModulesOptions::class, static function (Application $app): ModulesOptions {
            $config = $app->make(Repository::class);
            $primary = (array) $config->get('modules', []);
            $scoped = (array) $config->get('packman.modules', []);

            // Lists replace the complete discovery policy, including each path's segments.
            $discovery = array_intersect_key($scoped, array_flip(['package_types', 'extra_keys']));

            return ModulesOptions::fromArray($discovery + array_replace_recursive($primary, $scoped));
        });

        $app->singleton(FieldCastRegistry::class, FieldCastMap::class);
        $app->bindIf(ModuleCompilation::class, RegistryCompiler::class);
        $app->when(RegistryCompiler::class)->needs('$sections')->giveTagged(ManifestSectionCompiler::class);
        $app->when(RegistryCompiler::class)->needs('$installedJsonPath')->give(static fn (Application $app): string => $app->basePath(self::INSTALLED_JSON));
        $app->when(RegistryCompiler::class)->needs('$compilerPackage')->give(static fn (Application $app): string => $app->make(ModulesOptions::class)->compilerPackage);

        // Bind the concrete singleton first so module providers can resolve
        // it directly to call register() (not part of the read-only contract),
        // then alias the contract to the same instance.
        $app->singleton(InMemoryEntityRegistry::class, static fn (Application $app): InMemoryEntityRegistry => new InMemoryEntityRegistry(
            moduleActive: static fn (string $module, mixed $ctx): bool => $app
                ->make(ModuleRegistry::class)
                ->isActive($module, $ctx),
        ));
        $app->singleton(
            EntityRegistry::class,
            static fn (Application $app): InMemoryEntityRegistry => $app->make(InMemoryEntityRegistry::class),
        );

        // No fallback modules by default — real deploys scan installed.json;
        // tests/testbench rebind this with an injected class list.
        $app->singleton(ModuleDiscovery::class, static function (Application $app): ModuleDiscovery {
            $options = $app->make(ModulesOptions::class);

            return new ModuleDiscovery(
                installedJsonPath: $app->basePath(self::INSTALLED_JSON),
                packageTypes: $options->packageTypes,
                extraKeys: $options->extraKeys,
            );
        });

        if (! $app->bound(ModuleDiscoveryContract::class)) {
            $app->alias(ModuleDiscovery::class, ModuleDiscoveryContract::class);
        }

        // Boxed default: billing/entitlement gate is an application concern.
        $app->singletonIf(ModuleActivationGate::class, AllowAllActivationGate::class);

        // Registry cache WITHOUT tags: store/ttl/prefix from Options.
        $app->singletonIf(ModuleRegistryCache::class, static fn (Application $app): RepositoryModuleRegistryCache => new RepositoryModuleRegistryCache(
            store: static fn (): CacheRepository => $app->make(CacheFactory::class)->store($app->make(ModulesOptions::class)->cacheStore),
            options: $app->make(ModulesOptions::class),
        ));

        $app->singleton(ModuleRegistry::class, static function (Application $app): CompiledModuleRegistry {
            $options = $app->make(ModulesOptions::class);

            return new CompiledModuleRegistry(
                cachePath: $app->basePath($options->cachePath),
                stateReader: static fn (): ModuleStateReader => $app->make(ModuleStateReader::class),
                cache: static fn (): ModuleRegistryCache => $app->make(ModuleRegistryCache::class),
                failIfMissing: $app->environment('production'),
                compileCommand: $options->compileCommand,
            );
        });

        $app->singleton(RegistryIntrospection::class, static function (Application $app): ReadRegistryIntrospection {
            $options = $app->make(ModulesOptions::class);

            return new ReadRegistryIntrospection(
                registry: $app->make(ModuleRegistry::class),
                activationGate: $app->make(ModuleActivationGate::class),
                cachePath: $app->basePath($options->cachePath),
                failIfMissing: $app->environment('production'),
                installedJsonPath: $app->basePath(self::INSTALLED_JSON),
            );
        });
    }
}
