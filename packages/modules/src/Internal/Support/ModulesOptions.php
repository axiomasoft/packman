<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Support;

use Override;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;
use Packman\Modules\Contracts\Definition\ModuleDiscovery;

/** @internal Package composition type. */
#[Internal]
final readonly class ModulesOptions implements OptionsFactory
{
    /**
     * @param  list<string>  $packageTypes
     * @param  list<list<string>>  $extraKeys
     */
    public function __construct(
        public ?string $connection = null,
        public ?string $cacheStore = null,
        public int $cacheTtl = 3600,
        public string $cachePrefix = 'modules',
        public array $packageTypes = ModuleDiscovery::DEFAULT_PACKAGE_TYPES,
        public array $extraKeys = ModuleDiscovery::DEFAULT_EXTRA_KEYS,
        public string $configRoot = 'modules',
        public string $cachePath = 'bootstrap/cache/modules.php',
        public string $lockKey = 'modules:lifecycle-graph',
        public string $compilerPackage = 'axiomasoft/packman-modules',
        public string $modulesTable = 'mod_modules',
        public string $recordsTable = 'mod_records',
        public string $lifecycleLogTable = 'mod_lifecycle_log',
        public string $registrySchema = 'packman-registry/1',
        public string $compileCommand = 'modules:compile',
    ) {}

    /** @param  array<array-key, mixed>  $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'modules'): self
    {
        $violations = new ConfigurationValidator;

        $connection = self::nullableNonEmptyString($config['connection'] ?? null);

        $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        $cacheStore = self::nullableNonEmptyString($cache['store'] ?? null);
        $cacheTtl = self::positiveInt(violations: $violations, path: 'cache.ttl', value: $cache['ttl'] ?? 3600);
        $cachePrefix = self::nonEmpty(violations: $violations, path: 'cache.prefix', value: $cache['prefix'] ?? 'modules');

        $packageTypes = self::nonEmptyStringList(
            violations: $violations,
            path: 'package_types',
            value: $config['package_types'] ?? ModuleDiscovery::DEFAULT_PACKAGE_TYPES,
        );
        $extraKeys = self::extraKeyPaths(
            violations: $violations,
            path: 'extra_keys',
            value: $config['extra_keys'] ?? ModuleDiscovery::DEFAULT_EXTRA_KEYS,
        );

        $cachePath = self::nonEmpty(violations: $violations, path: 'cache_path', value: $config['cache_path'] ?? 'bootstrap/cache/modules.php');
        $lockKey = self::nonEmpty(violations: $violations, path: 'lock_key', value: $config['lock_key'] ?? 'modules:lifecycle-graph');
        $compilerPackage = self::nonEmpty(violations: $violations, path: 'compiler_package', value: $config['compiler_package'] ?? 'axiomasoft/packman-modules');

        $tables = is_array($config['tables'] ?? null) ? $config['tables'] : [];
        $modulesTable = self::nonEmpty(violations: $violations, path: 'tables.modules', value: $tables['modules'] ?? 'mod_modules');
        $recordsTable = self::nonEmpty(violations: $violations, path: 'tables.records', value: $tables['records'] ?? 'mod_records');
        $lifecycleLogTable = self::nonEmpty(violations: $violations, path: 'tables.lifecycle_log', value: $tables['lifecycle_log'] ?? 'mod_lifecycle_log');

        $registrySchema = self::nonEmpty(violations: $violations, path: 'registry_schema', value: $config['registry_schema'] ?? 'packman-registry/1');
        $compileCommand = self::nonEmpty(violations: $violations, path: 'compile_command', value: $config['compile_command'] ?? 'modules:compile');

        $violations->failIfAny();

        return new self(
            connection: $connection,
            cacheStore: $cacheStore,
            cacheTtl: $cacheTtl,
            cachePrefix: $cachePrefix,
            packageTypes: $packageTypes,
            extraKeys: $extraKeys,
            configRoot: $path !== '' ? $path : 'modules',
            cachePath: $cachePath,
            lockKey: $lockKey,
            compilerPackage: $compilerPackage,
            modulesTable: $modulesTable,
            recordsTable: $recordsTable,
            lifecycleLogTable: $lifecycleLogTable,
            registrySchema: $registrySchema,
            compileCommand: $compileCommand,
        );
    }

    private static function nonEmpty(ConfigurationValidator $violations, string $path, mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $violations->add(path: $path, message: 'must be a non-empty string');

        return 'invalid';
    }

    private static function nullableNonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function positiveInt(ConfigurationValidator $violations, string $path, mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        $violations->add(path: $path, message: 'must be a positive integer');

        return 1;
    }

    /** @return list<string> */
    private static function nonEmptyStringList(ConfigurationValidator $violations, string $path, mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            $violations->add(path: $path, message: 'must be a non-empty list of non-empty strings');

            return ModuleDiscovery::DEFAULT_PACKAGE_TYPES;
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                $violations->add(path: $path, message: 'must contain only non-empty strings');

                return ModuleDiscovery::DEFAULT_PACKAGE_TYPES;
            }

            $result[] = $item;
        }

        return $result;
    }

    /** @return list<list<string>> */
    private static function extraKeyPaths(ConfigurationValidator $violations, string $path, mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            $violations->add(path: $path, message: 'must be a non-empty list of key paths');

            return ModuleDiscovery::DEFAULT_EXTRA_KEYS;
        }

        $paths = [];

        foreach ($value as $segmentList) {
            if (is_string($segmentList) && $segmentList !== '') {
                $paths[] = array_values(array_filter(explode('.', $segmentList), static fn (string $part): bool => $part !== ''));

                continue;
            }

            if (! is_array($segmentList) || $segmentList === []) {
                $violations->add(path: $path, message: 'must contain non-empty key path lists');

                return ModuleDiscovery::DEFAULT_EXTRA_KEYS;
            }

            $normalized = [];

            foreach ($segmentList as $segment) {
                if (! is_string($segment) || $segment === '') {
                    $violations->add(path: $path, message: 'key path segments must be non-empty strings');

                    return ModuleDiscovery::DEFAULT_EXTRA_KEYS;
                }

                $normalized[] = $segment;
            }

            $paths[] = $normalized;
        }

        return $paths;
    }
}
