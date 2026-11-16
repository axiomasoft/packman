<?php

declare(strict_types=1);

namespace Packman\Panels\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Modules\Contracts\Definition\ModuleDiscovery;
use Packman\Modules\Contracts\Entities\EntityRegistry;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Panels\Contracts\Admission\PanelAdmissionGate;
use Packman\Panels\Contracts\Admission\PanelAdmitter;
use Packman\Panels\Contracts\Admission\PanelPolicyResolver;
use Packman\Panels\Contracts\Registry\PanelCatalogue;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Contracts\Subjects\PanelFormProjector;
use Packman\Panels\Contracts\Subjects\PanelSubjectResolver;
use Packman\Panels\Contracts\Subjects\SubjectChainResolver;
use Packman\Panels\Internal\Admission\PanelAdmission;
use Packman\Panels\Internal\Catalogue\InMemoryPanelCatalogue;
use Packman\Panels\Internal\Catalogue\ModulePanelCatalogue;
use Packman\Panels\Internal\Catalogue\PanelCompiler;
use Packman\Panels\Internal\Catalogue\PanelRegistryImpl;
use Packman\Panels\Internal\Configuration\PanelsOptions;
use Packman\Panels\Internal\Subjects\PanelFormProjection;
use Packman\Panels\Internal\Subjects\PanelSubjects;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use WeakMap;

/** Composition of `axiomasoft/packman-panels`. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class PanelsBindingsRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(PanelsOptions::class, static function (Application $app): PanelsOptions {
            $config = $app->make(Repository::class);
            $primary = (array) $config->get('packman-panels', []);
            $scoped = (array) $config->get('packman.panels', []);

            return PanelsOptions::fromArray(array_replace_recursive($primary, $scoped));
        });

        $app->singleton(PanelRegistry::class, static function (Application $app): PanelRegistryImpl {
            $compiler = new PanelCompiler(static fn (): array => $app->make(PanelsOptions::class)->defaults);

            return new PanelRegistryImpl($app, $compiler);
        });

        $app->singleton(PanelAdmission::class, static fn (Application $app): PanelAdmission => new PanelAdmission(
            gate: $app->make(PanelAdmissionGate::class),
        ));
        $app->alias(PanelAdmission::class, PanelAdmitter::class);

        $app->singleton(SubjectChainResolver::class, static fn (Application $app): PanelSubjects => new PanelSubjects(
            resolver: $app->make(PanelSubjectResolver::class),
        ));

        $app->singleton(PanelFormProjector::class, static fn (Application $app): PanelFormProjection => new PanelFormProjection(
            subjects: $app->make(SubjectChainResolver::class),
            policies: $app->make(PanelPolicyResolver::class),
        ));

        $app->singleton(PanelCatalogue::class, static function (Application $app): PanelCatalogue {
            self::registerExtensionPanels($app);
            $providers = self::panelProviders($app);

            if (
                interface_exists(ModuleRegistry::class)
                && interface_exists(EntityRegistry::class)
                && $app->bound(ModuleRegistry::class)
                && $app->bound(EntityRegistry::class)
            ) {
                return new ModulePanelCatalogue(
                    providers: $providers,
                    entities: $app->make(EntityRegistry::class),
                    modules: $app->make(ModuleRegistry::class),
                );
            }

            return new InMemoryPanelCatalogue(providers: $providers);
        });

        if (interface_exists(PluginInspector::class)) {
            $app->afterResolving(PluginInspector::class, static function (mixed $inspector) use ($app): void {
                if ($inspector instanceof PluginInspector) {
                    self::registerFromPluginInspector($app, $inspector);
                }
            });
        }
    }

    #[Override]
    public function boot(Application $app): void
    {
        $app->make(PanelsOptions::class);

        $app->booted(static function () use ($app): void {
            self::registerExtensionPanels($app);

            if ($app->bound(PanelRegistry::class)) {
                $registry = $app->make(PanelRegistry::class);

                if ($registry instanceof PanelRegistryImpl) {
                    $registry->freeze();
                }
            }
        });
    }

    private static function registerExtensionPanels(Application $app): void
    {
        if (interface_exists(PluginInspector::class) && $app->bound(PluginInspector::class)) {
            $shouldResolve = $app->resolved(PluginInspector::class)
                || ! class_exists(ExtensionRegistrar::class)
                || ExtensionRegistrar::hasPendingPlugins($app);

            if ($shouldResolve) {
                self::registerFromPluginInspector($app, $app->make(PluginInspector::class));
            }
        }

        if (interface_exists(ModuleDiscovery::class) && $app->bound(ModuleDiscovery::class)) {
            self::registerFromModuleDiscovery($app, $app->make(ModuleDiscovery::class));
        }
    }

    private static function registerFromPluginInspector(Application $app, PluginInspector $inspector): void
    {
        if (! self::markSourceSeen($app, $inspector)) {
            return;
        }

        foreach ($inspector->describe() as $plugin) {
            if ($plugin->facets !== null && $plugin->facets->panelProviders !== []) {
                PanelExtensionBridge::registerProviders($app, $plugin->facets->panelProviders);
            }
        }
    }

    private static function registerFromModuleDiscovery(Application $app, ModuleDiscovery $discovery): void
    {
        if (! self::markSourceSeen($app, $discovery)) {
            return;
        }

        foreach ($discovery->discover() as $manifest) {
            if ($manifest->panelProviders !== []) {
                PanelExtensionBridge::registerProviders($app, $manifest->panelProviders);
            }
        }
    }

    private static function markSourceSeen(Application $app, object $source): bool
    {
        /** @var WeakMap<object, WeakMap<object, true>>|null $seen */
        static $seen = null;

        if (! $seen instanceof WeakMap) {
            /** @var WeakMap<object, WeakMap<object, true>> $map */
            $map = new WeakMap;
            $seen = $map;
        }

        $scope = self::registrationScope($app);

        if ($seen->offsetExists($scope)) {
            $bucket = $seen->offsetGet($scope);
        } else {
            /** @var WeakMap<object, true> $bucket */
            $bucket = new WeakMap;
            $seen->offsetSet($scope, $bucket);
        }

        if ($bucket->offsetExists($source)) {
            return false;
        }

        $bucket->offsetSet($source, true);

        return true;
    }

    private static function registrationScope(Application $app): object
    {
        if (! $app->bound(PanelRegistry::class)) {
            return $app;
        }

        return $app->make(PanelRegistry::class);
    }

    /** @return iterable<PanelProvider> */
    private static function panelProviders(Application $app): iterable
    {
        /** @var iterable<PanelProvider> $providers */
        $providers = $app->tagged($app->make(PanelsOptions::class)->providerTag);

        return $providers;
    }
}
