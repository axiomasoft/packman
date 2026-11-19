<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Discovery;

use Packman\Attributes\Internal;
use Packman\Extensions\ExtensionInspector;
use Packman\Modules\Contracts\Definition\ModuleDefinition;
use Packman\Modules\Data\Lifecycle\ModuleManifest;
use Packman\Modules\Data\Settings\DefaultSettingDefinition;
use Packman\Modules\Exceptions\Compiler\InvalidManifestException;

/**
 * Finds installed modules and builds their manifests. Default source is
 * `vendor/composer/installed.json` scanning configured package types and extra
 * key paths; an injected fallback list lets tests and testbench avoid depending
 * on real installed.json contents.
 *
 * @internal spec: B-10 §3.2/§5.2
 */
#[Internal]
final readonly class ModuleDiscovery implements \Packman\Modules\Contracts\Definition\ModuleDiscovery
{
    /**
     * @param  list<class-string<ModuleDefinition>>  $fallbackModules  injectable seam for tests/testbench.
     * @param  list<string>  $packageTypes
     * @param  list<list<string>>  $extraKeys
     */
    public function __construct(
        private array $fallbackModules = [],
        private ?string $installedJsonPath = null,
        private array $packageTypes = self::DEFAULT_PACKAGE_TYPES,
        private array $extraKeys = self::DEFAULT_EXTRA_KEYS,
    ) {}

    /** @return list<ModuleManifest> */
    public function discover(): array
    {
        $classes = $this->fallbackModules !== [] ? $this->fallbackModules : $this->scanInstalledJson();

        return array_map($this->manifestFor(...), $classes);
    }

    private function manifestFor(string $class): ModuleManifest
    {
        if (! is_subclass_of($class, ModuleDefinition::class)) {
            throw InvalidManifestException::notAModuleDefinition($class);
        }

        $module = new $class;
        $manifest = $module->manifest();

        return $this->hydrateExtensionFacets($module, $manifest);
    }

    private function hydrateExtensionFacets(ModuleDefinition $module, ModuleManifest $manifest): ModuleManifest
    {
        $facets = ExtensionInspector::inspect($module);

        if ($manifest->tablePrefix === null && $facets->tablePrefix !== null && $facets->tablePrefix !== '') {
            $manifest->tablePrefix($facets->tablePrefix);
        }

        if ($manifest->settingsNamespace === null && $facets->settingsNamespace !== null && trim($facets->settingsNamespace) !== '') {
            $manifest->settingsNamespace($facets->settingsNamespace);
        }

        if ($manifest->settings === [] && $facets->hasDatabaseSettings && $facets->defaultSettings !== []) {
            $definitions = [];

            foreach ($facets->defaultSettings as $key => $default) {
                $definition = DefaultSettingDefinition::make($key, $default);

                if (in_array($key, $facets->sensitiveSettings, true)) {
                    $definition = $definition->sensitive();
                }

                $definitions[] = $definition;
            }

            $manifest->settings($definitions);
        }

        if ($manifest->featureFlags === [] && $facets->featureFlags !== []) {
            $manifest->featureFlags($facets->featureFlags);
        }

        if ($manifest->onEnableHook === null && $facets->onEnableHook !== null && class_exists($facets->onEnableHook)) {
            $manifest->onEnable($facets->onEnableHook);
        }

        if ($manifest->onDisableHook === null && $facets->onDisableHook !== null && class_exists($facets->onDisableHook)) {
            $manifest->onDisable($facets->onDisableHook);
        }

        if ($manifest->onPurgeHook === null && $facets->onPurgeHook !== null && class_exists($facets->onPurgeHook)) {
            $manifest->onPurge($facets->onPurgeHook);
        }

        if ($manifest->panelProviders === [] && $facets->panelProviders !== []) {
            $manifest->panelProviders($facets->panelProviders);
        }

        return $manifest;
    }

    /** @return list<string> */
    private function scanInstalledJson(): array
    {
        $path = $this->installedJsonPath;

        if ($path === null || ! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            return [];
        }

        /** @var list<mixed> $packages */
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;

        $classes = [];

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $type = $package['type'] ?? null;

            if (! is_string($type) || ! in_array($type, $this->packageTypes, true)) {
                continue;
            }

            $extra = $package['extra'] ?? null;

            if (! is_array($extra)) {
                continue;
            }

            $resolved = $this->resolveModuleClass($extra);

            if ($resolved !== null) {
                $classes[] = $resolved;
            }
        }

        return $classes;
    }

    /** @param array<array-key, mixed> $extra */
    private function resolveModuleClass(array $extra): ?string
    {
        foreach ($this->extraKeys as $segments) {
            $cursor = $extra;
            $found = true;
            $lastIndex = count($segments) - 1;

            foreach ($segments as $index => $segment) {
                if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                    $found = false;

                    break;
                }

                if ($index === $lastIndex) {
                    $value = $cursor[$segment];

                    // An absent module key marks a non-modular package; a present non-string value is invalid.
                    return is_string($value) ? $value : get_debug_type($value);
                }

                $cursor = $cursor[$segment];
            }

            if ($found) {
                break;
            }
        }

        return null;
    }
}
