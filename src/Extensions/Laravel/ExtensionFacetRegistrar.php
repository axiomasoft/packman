<?php

declare(strict_types=1);

namespace Packman\Extensions\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\ConfigSection;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\HealthCheck;
use Packman\Doctor\Laravel\DoctorChecks;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Laravel\ProblemDefinitions;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Extensions\ExtensionInspector;
use ReflectionClass;
use WeakMap;

/**
 * Shared Laravel registrar wiring kernel facets declared through `WithConfiguration` and `WithDiagnostics`.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ExtensionFacetRegistrar
{
    /** @param object|class-string $extension */
    public static function register(Application $app, object|string $extension, ?string $composerManifest = null): ExtensionFacetProfile
    {
        $instance = is_string($extension) ? self::instantiate($extension) : $extension;
        $profile = ExtensionInspector::inspect($instance);
        $manifest = $composerManifest ?? self::discoverComposerManifest($instance::class);

        self::registerConfiguration(
            app: $app,
            configRoot: $profile->configRoot ?? '',
            defaultConfigPath: $profile->defaultConfigPath,
            optionsClass: $profile->optionsClass,
            configSectionClass: $profile->configSectionClass,
        );

        self::registerDiagnostics(
            app: $app,
            healthChecks: $profile->healthChecks,
            problemProviders: $profile->problemProviders,
            composerManifest: $manifest,
        );

        return $profile;
    }

    /**
     * @param  class-string<OptionsFactory>|null  $optionsClass
     * @param  class-string<ConfigSection>|null  $configSectionClass
     */
    public static function registerConfiguration(
        Application $app,
        string $configRoot,
        ?string $defaultConfigPath,
        ?string $optionsClass,
        ?string $configSectionClass = null,
    ): void {
        if ($configRoot !== '') {
            if (
                $defaultConfigPath !== null
                && is_file($defaultConfigPath)
                && $app->bound(Repository::class)
                && self::markConfigDefaultsMerged($app, $configRoot, $defaultConfigPath)
            ) {
                $configRepo = $app->make(Repository::class);
                $defaults = require $defaultConfigPath;

                if (is_array($defaults)) {
                    if (! $configRepo->has($configRoot)) {
                        $configRepo->set($configRoot, $defaults);
                    } else {
                        $existing = $configRepo->get($configRoot);

                        if (is_array($existing)) {
                            $configRepo->set($configRoot, self::mergeConfigDefaults($defaults, $existing));
                        }
                    }
                }
            }

            if ($optionsClass !== null && is_subclass_of($optionsClass, OptionsFactory::class)) {
                $app->singletonIf($optionsClass, static function (Application $app) use ($optionsClass, $configRoot): OptionsFactory {
                    if (! $app->bound(Repository::class)) {
                        return $optionsClass::fromArray([], $configRoot);
                    }

                    $repo = $app->make(Repository::class);

                    if (! $repo->has($configRoot)) {
                        return $optionsClass::fromArray([], $configRoot);
                    }

                    $raw = $repo->get($configRoot);

                    if (! is_array($raw)) {
                        throw new InvalidConfiguration([$configRoot.': expected an array']);
                    }

                    return $optionsClass::fromArray($raw, $configRoot);
                });

                ConfigSectionRegistration::registerValidator(
                    $app,
                    $optionsClass,
                    static function () use ($app, $optionsClass, $configRoot): void {
                        if (! $app->bound(Repository::class)) {
                            $optionsClass::fromArray([], $configRoot);

                            return;
                        }

                        $repo = $app->make(Repository::class);

                        if (! $repo->has($configRoot)) {
                            $optionsClass::fromArray([], $configRoot);

                            return;
                        }

                        $raw = $repo->get($configRoot);

                        if (! is_array($raw)) {
                            throw new InvalidConfiguration([$configRoot.': expected an array']);
                        }

                        $optionsClass::fromArray($raw, $configRoot);
                    },
                );
            }
        }

        if ($configSectionClass !== null && is_subclass_of($configSectionClass, ConfigSection::class)) {
            ConfigSectionRegistration::register($app, $configSectionClass);
        }
    }

    /**
     * @param  list<class-string>  $healthChecks
     * @param  list<class-string>  $problemProviders
     */
    public static function registerDiagnostics(
        Application $app,
        array $healthChecks,
        array $problemProviders = [],
        ?string $composerManifest = null,
    ): void {
        foreach ($healthChecks as $checkClass) {
            if (is_subclass_of($checkClass, DoctorCheck::class)) {
                DoctorChecks::register($app, $checkClass);
            } elseif (is_subclass_of($checkClass, HealthCheck::class)) {
                DoctorChecks::registerHealthChecks($app, $checkClass);
            }
        }

        if ($problemProviders === [] || $app->resolved(ProblemRegistry::class)) {
            return;
        }

        foreach ($problemProviders as $provider) {
            if (! is_subclass_of($provider, ProblemDefinitionProvider::class)) {
                continue;
            }

            $manifest = $composerManifest ?? self::discoverComposerManifest($provider);

            if ($manifest !== null && is_file($manifest)) {
                ProblemDefinitions::register($app, $manifest, $provider);
            }
        }
    }

    /** @param class-string $extensionClass */
    public static function discoverComposerManifest(string $extensionClass): ?string
    {
        if (! class_exists($extensionClass)) {
            return null;
        }

        $file = new ReflectionClass($extensionClass)->getFileName();

        if (! is_string($file)) {
            return null;
        }

        $dir = dirname($file);

        while (true) {
            $candidate = $dir.'/composer.json';

            if (is_file($candidate)) {
                return $candidate;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                return null;
            }

            $dir = $parent;
        }
    }

    /** @param class-string $class */
    private static function instantiate(string $class): object
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException("Extension class [{$class}] does not exist.");
        }

        return new $class;
    }

    private static function markConfigDefaultsMerged(Application $app, string $configRoot, string $defaultConfigPath): bool
    {
        /** @var WeakMap<Application, array<string, true>>|null $merged */
        static $merged = null;

        if (! $merged instanceof WeakMap) {
            /** @var WeakMap<Application, array<string, true>> $map */
            $map = new WeakMap;
            $merged = $map;
        }

        $entries = $merged->offsetExists($app) ? $merged->offsetGet($app) : [];
        $key = $configRoot.'|'.$defaultConfigPath;

        if (isset($entries[$key])) {
            return false;
        }

        $entries[$key] = true;
        $merged->offsetSet($app, $entries);

        return true;
    }

    /**
     * Merges associative maps recursively while letting host lists and empty arrays replace defaults wholesale.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $existing
     * @return array<array-key, mixed>
     */
    private static function mergeConfigDefaults(array $defaults, array $existing): array
    {
        if (array_is_list($defaults) || array_is_list($existing)) {
            return $existing !== [] ? $existing : $defaults;
        }

        $merged = $defaults;

        foreach ($existing as $key => $value) {
            $default = $merged[$key] ?? null;

            if (is_array($default) && is_array($value) && $default !== [] && $value !== [] && ! array_is_list($default) && ! array_is_list($value)) {
                $merged[$key] = self::mergeConfigDefaults($default, $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
