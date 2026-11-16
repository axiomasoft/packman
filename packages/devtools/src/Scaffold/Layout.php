<?php

declare(strict_types=1);

namespace Packman\DevTools\Scaffold;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Generates file trees for all 4 supported package profiles: php-library, laravel-package, packman-plugin, packman-module.
 */
#[Api(since: '1.0')]
final readonly class Layout
{
    /** @var list<string> */
    public const array PROFILES = [
        'php-library',
        'laravel-package',
        'packman-plugin',
        'packman-module',
    ];

    private StubStore $stubs;

    public function __construct(?StubStore $stubs = null)
    {
        $this->stubs = $stubs ?? new StubStore(StubStore::defaultRoot());
    }

    public function descriptor(string $profile): ProfileDescriptor
    {
        return match ($profile) {
            'php-library' => new ProfileDescriptor('php-library', 'Framework-agnostic PHP library with Contracts/Data/Internal separation.', ['Contracts', 'Data', 'Enums', 'Exceptions', 'Internal']),
            'laravel-package' => new ProfileDescriptor('laravel-package', 'Laravel package with slim ServiceProvider and PackageRegistrar.', ['Contracts', 'Data', 'Enums', 'Exceptions', 'Internal', 'Laravel']),
            'packman-plugin' => new ProfileDescriptor('packman-plugin', 'Stateless Packman code plugin implementing ExtensionPlugin + WithConfiguration.', ['Contracts', 'Data', 'Internal', 'Laravel']),
            'packman-module' => new ProfileDescriptor('packman-module', 'DB-backed Packman module extending ModuleDefinition and composing With* facets.', ['Contracts', 'Data', 'Internal', 'Laravel', 'Schema']),
            default => throw new InvalidArgumentException("Unknown scaffold profile [{$profile}]. Supported: ".implode(', ', self::PROFILES)),
        };
    }

    /** @return list<GeneratedFile> */
    public function filesFor(string $profile, Place $place, bool $overwrite = false): array
    {
        $this->descriptor($profile);
        $vars = $this->variablesFor($profile, $place);
        $base = $place->relativeDirectory;

        $files = [
            new GeneratedFile("{$base}/composer.json", $this->stubs->render("{$profile}/composer.json.stub", $vars), $overwrite),
            new GeneratedFile("{$base}/README.md", $this->stubs->render("{$profile}/README.md.stub", $vars), $overwrite),
            new GeneratedFile("{$base}/CHANGELOG.md", $this->stubs->render("{$profile}/CHANGELOG.md.stub", $vars), $overwrite),
        ];

        return match ($profile) {
            'php-library' => [
                ...$files,
                new GeneratedFile("{$base}/src/Contracts/{$place->classPrefix}Contract.php", $this->stubs->render('php-library/src/Contracts/Contract.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/src/Data/{$place->classPrefix}Options.php", $this->stubs->render('php-library/src/Data/Options.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/src/Internal/Default{$place->classPrefix}.php", $this->stubs->render('php-library/src/Internal/DefaultService.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/tests/Unit/{$place->classPrefix}Test.php", $this->stubs->render('php-library/tests/Unit/PackageTest.php.stub', $vars), $overwrite),
            ],
            'laravel-package' => [
                ...$files,
                new GeneratedFile("{$base}/config/{$place->slug}.php", $this->stubs->render('laravel-package/config/config.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/src/Laravel/{$place->classPrefix}BindingsRegistrar.php", $this->stubs->render('laravel-package/src/Laravel/BindingsRegistrar.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/src/Laravel/{$place->classPrefix}ServiceProvider.php", $this->stubs->render('laravel-package/src/ServiceProvider.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/tests/Feature/{$place->classPrefix}ServiceProviderTest.php", $this->stubs->render('laravel-package/tests/Feature/ServiceProviderTest.php.stub', $vars), $overwrite),
            ],
            'packman-plugin' => [
                ...$files,
                new GeneratedFile("{$base}/config/{$place->slug}.php", $this->stubs->render('packman-plugin/config/config.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/src/{$place->classPrefix}Plugin.php", $this->stubs->render('packman-plugin/src/Plugin.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/src/Laravel/{$place->classPrefix}ServiceProvider.php", $this->stubs->render('packman-plugin/src/ServiceProvider.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/tests/Feature/{$place->classPrefix}PluginTest.php", $this->stubs->render('packman-plugin/tests/Feature/PluginContractTest.php.stub', $vars), $overwrite),
            ],
            'packman-module' => [
                ...$files,
                new GeneratedFile("{$base}/config/{$place->slug}.php", $this->stubs->render('packman-module/config/config.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/src/{$place->classPrefix}Module.php", $this->stubs->render('packman-module/src/Module.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/src/Laravel/{$place->classPrefix}ServiceProvider.php", $this->stubs->render('packman-module/src/ServiceProvider.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/src/Schema/ModuleSchemaSetProvider.php", $this->stubs->render('packman-module/src/Schema/ModuleSchemaSetProvider.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/database/migrations/tenant/2026_11_01_000001_create_{$place->tablePrefix}_records_table.php", $this->stubs->render('packman-module/database/migrations/tenant/create_table.php.stub', $vars), $overwrite),
                new GeneratedFile("{$base}/tests/Feature/{$place->classPrefix}ModuleTest.php", $this->stubs->render('packman-module/tests/Feature/ModuleContractTest.php.stub', $vars), $overwrite),
            ],
            default => throw new InvalidArgumentException("Unknown scaffold profile [{$profile}]. Supported: ".implode(', ', self::PROFILES)),
        };
    }

    /** @return array<string, string> */
    private function variablesFor(string $profile, Place $place): array
    {
        return [
            'profile' => $profile,
            'vendor' => $place->vendor,
            'slug' => $place->slug,
            'composer_name' => $place->composerName,
            'namespace' => $place->namespace,
            'namespace_escaped' => str_replace('\\', '\\\\', $place->namespace).'\\\\',
            'class_prefix' => $place->classPrefix,
            'table_prefix' => $place->tablePrefix,
            'plugin_id' => $place->pluginId,
            'config_key' => $place->slug,
        ];
    }
}
