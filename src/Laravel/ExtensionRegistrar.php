<?php

declare(strict_types=1);

namespace Packman\Plugins\Laravel;

use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as FoundationApplication;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\HealthCheck;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Contracts\Contributions\Contributions;
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

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ExtensionRegistrar implements PackageRegistrar
{
    private const string DOCTOR_CHECK_TAG = 'packman.doctor_checks';

    private const string HEALTH_CHECK_TAG = 'packman.health_checks';

    /** @param class-string<ExtensionPlugin> ...$plugins */
    public static function builtIn(string ...$plugins): void
    {
        $container = Container::getInstance();
        $container->singletonIf(PluginCatalog::class, static fn (): PluginCatalog => new PluginCatalog);
        $catalog = $container->make(PluginCatalog::class);

        foreach ($plugins as $plugin) {
            $catalog->builtIn($plugin);
        }
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
            $configured = [];
            $disabled = [];

            if ($app->bound(Repository::class)) {
                $config = $app->make(Repository::class);
                $raw = $config->get('packman.plugins', []);
                $configured = is_array($raw) ? array_values($raw) : [];

                $rawDisabled = $config->get('packman.disabled_plugins', []);
                $disabled = is_array($rawDisabled)
                    ? array_values(array_filter($rawDisabled, static fn (mixed $v): bool => is_string($v) && $v !== ''))
                    : [];
            }

            $gate = $app->bound(PluginActivationGate::class)
                ? $app->make(PluginActivationGate::class)
                : null;

            $catalog = $app->make(PluginCatalog::class);
            $catalog->seal();

            return new PluginGraphCompiler($app, activationGate: $gate)->compile($catalog->entries($configured), $disabled);
        });

        $app->alias(FrozenContributions::class, Contributions::class);
        $app->alias(FrozenContributions::class, PluginInspector::class);

        $app->afterResolving(FrozenContributions::class, static function (FrozenContributions $contributions, Application $app): void {
            self::wirePluginFacets($app, $contributions);
        });

        $app->singleton(Capabilities::class, static fn (Application $app): Capabilities => new CapabilitiesRegistry(
            $app->make(FrozenContributions::class),
            $app,
        ));

        $app->singleton(ExtensionRegistry::class, static fn (Application $app): ExtensionRegistry => new ExtensionRegistry(
            $app->make(FrozenContributions::class),
            $app,
        ));
    }

    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            Artisan::starting(self::registerCommands(...));
        }
    }

    private static function wirePluginFacets(Application $app, FrozenContributions $contributions): void
    {
        $schemaNouns = [];

        foreach ($contributions->describe() as $plugin) {
            $facet = $plugin->facets;

            if ($facet === null) {
                continue;
            }

            if ($facet->hasConfiguration && $facet->configRoot !== null && $facet->configRoot !== '') {
                $configRoot = $facet->configRoot;

                if ($facet->defaultConfigPath !== null && is_file($facet->defaultConfigPath) && $app->bound(Repository::class)) {
                    $configRepo = $app->make(Repository::class);
                    $defaults = require $facet->defaultConfigPath;

                    if (is_array($defaults)) {
                        $existing = $configRepo->get($configRoot, []);
                        $configRepo->set($configRoot, array_replace_recursive($defaults, is_array($existing) ? $existing : []));
                    }
                }

                if ($facet->optionsClass !== null && is_subclass_of($facet->optionsClass, OptionsFactory::class)) {
                    $optionsClass = $facet->optionsClass;
                    $app->singletonIf($optionsClass, static function (Application $app) use ($optionsClass, $configRoot): OptionsFactory {
                        $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get($configRoot, []) : [];

                        return $optionsClass::fromArray(is_array($raw) ? $raw : [], $configRoot);
                    });
                }
            }

            if ($facet->hasSchema && $facet->tableNouns !== []) {
                $schemaNouns[$plugin->id] = $facet->tableNouns;
            }

            foreach ($facet->healthChecks as $checkClass) {
                if (is_subclass_of($checkClass, DoctorCheck::class)) {
                    $app->singletonIf($checkClass);
                    $app->tag([$checkClass], self::DOCTOR_CHECK_TAG);
                } elseif (is_subclass_of($checkClass, HealthCheck::class)) {
                    $app->singletonIf($checkClass);
                    $app->tag([$checkClass], self::HEALTH_CHECK_TAG);
                }
            }
        }

        if ($schemaNouns !== [] && interface_exists(SchemaOwnership::class)) {
            $declareAll = static function (SchemaOwnership $ownership) use ($schemaNouns): void {
                foreach ($schemaNouns as $owner => $nouns) {
                    $ownership->declare($owner, $nouns);
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
