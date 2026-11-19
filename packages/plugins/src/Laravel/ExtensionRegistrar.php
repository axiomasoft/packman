<?php

declare(strict_types=1);

namespace Packman\Plugins\Laravel;

use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as FoundationApplication;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Contributions\Extensions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Internal\Capabilities\CapabilitiesRegistry;
use Packman\Plugins\Internal\Contributions\ExtensionRegistry;
use Packman\Plugins\Internal\Contributions\FrozenContributions;
use Packman\Plugins\Internal\Graph\PluginCatalog;
use Packman\Plugins\Internal\Graph\PluginGraphCompiler;
use Packman\Plugins\Laravel\Console\ExtensionsCommand;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Laravel\SchemaExtensions;
use ReflectionClass;
use Throwable;
use WeakMap;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ExtensionRegistrar implements PackageRegistrar
{
    /** @param class-string<ExtensionPlugin> ...$plugins */
    public static function builtIn(string ...$plugins): void
    {
        self::builtInFor(Container::getInstance(), ...$plugins);
    }

    /** @param class-string<ExtensionPlugin> ...$plugins */
    public static function builtInFor(ContainerContract $container, string ...$plugins): void
    {
        $catalog = self::catalog($container);

        foreach ($plugins as $plugin) {
            $catalog->builtIn($plugin);
        }
    }

    /** @param class-string<PluginActivationGate> $gate */
    public static function addActivationGate(ContainerContract $container, string $gate): void
    {
        self::catalog($container)->addActivationGate($gate);
    }

    /** @param class-string<PluginActivationGate>|null $except Optional local gate replaced by an explicit preview configuration. */
    public static function activationGateFor(ContainerContract $container, ?string $except = null): ?PluginActivationGate
    {
        return self::catalog($container)->activationGate($container, $except);
    }

    private static function catalog(ContainerContract $container): PluginCatalog
    {
        $container->singletonIf(PluginCatalog::class, static fn (): PluginCatalog => new PluginCatalog);

        return $container->make(PluginCatalog::class);
    }

    public static function hasPendingPlugins(Application $app): bool
    {
        if (
            $app->resolved(FrozenContributions::class)
            || $app->resolved(PluginInspector::class)
            || ! $app->bound(FrozenContributions::class)
        ) {
            return true;
        }

        if ($app instanceof FoundationApplication && array_key_exists(PluginInspector::class, $app->getBindings())) {
            return true;
        }

        if ($app->bound(PluginCatalog::class) && $app->make(PluginCatalog::class)->hasBuiltIn()) {
            return true;
        }

        if ($app->bound(Repository::class)) {
            $configured = $app->make(Repository::class)->get('packman.plugins', []);

            if (is_array($configured) && $configured !== []) {
                return true;
            }
        }

        return false;
    }

    public function register(Application $app): void
    {
        $app->singletonIf(PluginCatalog::class, static fn (): PluginCatalog => new PluginCatalog);

        $app->singleton(FrozenContributions::class, static function (Application $app): FrozenContributions {
            [$configured, $disabled] = self::pluginConfiguration($app);
            $catalog = $app->make(PluginCatalog::class);
            $catalog->seal();
            $gate = $catalog->activationGate($app);

            return new PluginGraphCompiler($app, activationGate: $gate)->compile($catalog->entries($configured), $disabled);
        });

        $app->alias(FrozenContributions::class, Contributions::class);
        $app->alias(FrozenContributions::class, PluginInspector::class);

        $app->beforeResolving(OptionsFactory::class, static function (string $abstract, array $parameters, mixed $container): void {
            if (! $container instanceof Application || $container->bound($abstract)) {
                return;
            }

            self::registerPluginOptions($container);
        });

        $app->afterResolving(FrozenContributions::class, static function (FrozenContributions $contributions, Application $app): void {
            self::wirePluginFacets($app, $contributions);
        });

        self::registerSchemaResolutionHooks($app);

        $app->singleton(Capabilities::class, static fn (Application $app): Capabilities => new CapabilitiesRegistry(
            $app->make(FrozenContributions::class),
            $app,
        ));

        $app->singleton(ExtensionRegistry::class, static fn (Application $app): ExtensionRegistry => new ExtensionRegistry(
            $app->make(FrozenContributions::class),
            $app,
        ));
        $app->alias(ExtensionRegistry::class, Extensions::class);
    }

    public function boot(Application $app): void
    {
        self::registerPluginOptions($app);

        if ($app->runningInConsole()) {
            Artisan::starting(self::registerCommands(...));
        }
    }

    private static function registerPluginOptions(Application $app): void
    {
        /** @var WeakMap<Application, true>|null $registering */
        static $registering = null;
        $registering ??= new WeakMap;

        // A gate can resolve its own options through this same container hook.
        if (isset($registering[$app])) {
            return;
        }

        $registering[$app] = true;

        try {
            self::registerEarlyFacets($app);
        } finally {
            unset($registering[$app]);
        }
    }

    private static function registerEarlyFacets(Application $app): void
    {
        [$configured, $disabledIds] = self::pluginConfiguration($app);
        $disabled = array_fill_keys($disabledIds, true);
        $classes = [...self::catalog($app)->builtInClasses(), ...array_filter($configured, is_string(...))];
        $gate = null;

        foreach (array_unique($classes) as $pluginClass) {
            if (! is_subclass_of($pluginClass, ExtensionPlugin::class) || (! is_subclass_of($pluginClass, WithConfiguration::class) && ! is_subclass_of($pluginClass, WithDiagnostics::class))) {
                continue;
            }

            $reflection = new ReflectionClass($pluginClass);
            $constructor = $reflection->getConstructor();

            if ($reflection->isAbstract() || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
                continue;
            }

            try {
                $plugin = $reflection->newInstance();
                $id = $plugin->manifest()->id;
            } catch (Throwable) {
                continue;
            }

            if (isset($disabled[$id])) {
                continue;
            }

            $gate ??= self::activationGateFor($app);

            if ($gate !== null && ! $gate->isEnabled($id)) {
                continue;
            }

            if ($plugin instanceof WithConfiguration) {
                ExtensionFacetRegistrar::registerConfiguration(
                    app: $app,
                    configRoot: $plugin->configRoot(),
                    defaultConfigPath: $plugin->defaultConfigPath(),
                    optionsClass: $plugin->optionsClass(),
                    configSectionClass: $plugin->configSectionClass(),
                );
            }

            if ($plugin instanceof WithDiagnostics) {
                ExtensionFacetRegistrar::registerDiagnostics(
                    app: $app,
                    healthChecks: $plugin->healthChecks(),
                    problemProviders: $plugin->problemProviders(),
                    composerManifest: ExtensionFacetRegistrar::discoverComposerManifest($plugin::class),
                );
            }
        }
    }

    /** @return array{list<mixed>, list<string>} */
    private static function pluginConfiguration(Application $app): array
    {
        if (! $app->bound(Repository::class)) {
            return [[], []];
        }

        $config = $app->make(Repository::class);
        $plugins = $config->get('packman.plugins', []);
        $disabled = $config->get('packman.disabled_plugins', []);

        return [
            is_array($plugins) ? array_values($plugins) : [],
            is_array($disabled) ? array_values(array_filter($disabled, static fn (mixed $id): bool => is_string($id) && $id !== '')) : [],
        ];
    }

    private static function registerSchemaResolutionHooks(Application $app): void
    {
        $ensurePluginsWired = static function (string $abstract, array $parameters, mixed $container): void {
            /** @var WeakMap<Application, true>|null $resolvingApps */
            static $resolvingApps = null;
            $resolvingApps ??= new WeakMap;

            if (! $container instanceof Application || isset($resolvingApps[$container]) || $container->resolved(FrozenContributions::class)) {
                return;
            }

            if (! self::hasPendingPlugins($container)) {
                return;
            }

            $resolvingApps[$container] = true;

            try {
                $contributions = $container->make(FrozenContributions::class);
                self::wirePluginFacets($container, $contributions);
            } finally {
                unset($resolvingApps[$container]);
            }
        };

        foreach ([SchemaMigrator::class, SchemaSets::class, SchemaOwnership::class] as $contract) {
            if (interface_exists($contract)) {
                $app->beforeResolving($contract, $ensurePluginsWired);
            }
        }
    }

    private static function wirePluginFacets(Application $app, FrozenContributions $contributions): void
    {
        /** @var WeakMap<FrozenContributions, WeakMap<Application, true>>|null $wired */
        static $wired = null;

        if (! $wired instanceof WeakMap) {
            /** @var WeakMap<FrozenContributions, WeakMap<Application, true>> $outer */
            $outer = new WeakMap;
            $wired = $outer;
        }

        if (! $wired->offsetExists($contributions)) {
            /** @var WeakMap<Application, true> $inner */
            $inner = new WeakMap;
            $wired->offsetSet($contributions, $inner);
        }

        $appsForContributions = $wired->offsetGet($contributions);

        if ($appsForContributions->offsetExists($app)) {
            return;
        }

        $appsForContributions->offsetSet($app, true);

        $schemaNouns = [];
        $hasSchemaExtensions = false;

        foreach ($contributions->describe() as $plugin) {
            $facet = $plugin->facets;

            if ($facet === null) {
                continue;
            }

            if ($facet->hasConfiguration && $facet->configRoot !== null && $facet->configRoot !== '') {
                ExtensionFacetRegistrar::registerConfiguration(
                    app: $app,
                    configRoot: $facet->configRoot,
                    defaultConfigPath: $facet->defaultConfigPath,
                    optionsClass: $facet->optionsClass,
                    configSectionClass: $facet->configSectionClass,
                );
            }

            if ($facet->hasSchema) {
                $hasSchemaExtensions = true;

                if ($facet->tableNouns !== []) {
                    $schemaNouns[$plugin->id] = [$facet->tablePrefix, $facet->tableNouns];
                }

                if ($facet->schemaSetProviders !== [] && class_exists(SchemaExtensions::class)) {
                    SchemaExtensions::registerProviders($app, ...$facet->schemaSetProviders);
                }
            }

            ExtensionFacetRegistrar::registerDiagnostics(
                app: $app,
                healthChecks: $facet->healthChecks,
                problemProviders: $facet->problemProviders,
                composerManifest: ExtensionFacetRegistrar::discoverComposerManifest($plugin->class),
            );
        }

        if ($hasSchemaExtensions && class_exists(SchemaExtensions::class)) {
            SchemaExtensions::registerExtensionModuleBoundary($app);
        }

        if ($schemaNouns !== [] && interface_exists(SchemaOwnership::class) && class_exists(SchemaExtensions::class)) {
            $declareAll = static function (SchemaOwnership $ownership) use ($schemaNouns): void {
                foreach ($schemaNouns as $owner => [$prefix, $nouns]) {
                    SchemaExtensions::ownershipFor($ownership, $prefix)->declare($owner, $nouns);
                }
            };

            if ($app->resolved(SchemaOwnership::class)) {
                $declareAll($app->make(SchemaOwnership::class));
            } else {
                $app->afterResolving(SchemaOwnership::class, static function (mixed $ownership) use ($declareAll): void {
                    if ($ownership instanceof SchemaOwnership) {
                        $declareAll($ownership);
                    }
                });
            }
        }
    }

    private static function registerCommands(Artisan $artisan): void
    {
        $artisan->resolveCommands([ExtensionsCommand::class]);
    }
}
