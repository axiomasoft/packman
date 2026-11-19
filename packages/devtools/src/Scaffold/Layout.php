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

    private const string IDENTIFIER_SEGMENT = '/\A[A-Z][A-Za-z0-9]*\z/';

    private StubStore $stubs;

    public function __construct(?StubStore $stubs = null)
    {
        $this->stubs = $stubs ?? new StubStore(StubStore::defaultRoot());
    }

    public static function relative(string $basePath, string $path): string
    {
        $base = rtrim($basePath, '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    public static function absolute(string $basePath, string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($basePath, '/').'/'.$path;
    }

    public static function className(string $input, string $suffix = ''): string
    {
        $name = self::studlyIdentifier($input);

        if ($suffix !== '' && str_ends_with($name, $suffix) && $name !== $suffix) {
            $name = substr($name, 0, -strlen($suffix));
        }

        if (preg_match(self::IDENTIFIER_SEGMENT, $name) !== 1) {
            throw new InvalidArgumentException('"'.$input.'" is not a valid class name.');
        }

        return $name.$suffix;
    }

    /**
     * @return non-empty-list<string>
     */
    public static function segments(string $input): array
    {
        $trimmed = trim($input, '/\\');

        if ($trimmed === '') {
            throw new InvalidArgumentException('"'.$input.'" is not a valid path segment list.');
        }

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $trimmed)) as $segment) {
            $name = self::studlyIdentifier($segment);

            if (preg_match(self::IDENTIFIER_SEGMENT, $name) !== 1) {
                throw new InvalidArgumentException('"'.$input.'" contains invalid segment "'.$segment.'".');
            }

            $segments[] = $name;
        }

        return $segments;
    }

    private static function studlyIdentifier(string $value): string
    {
        $normalized = str_replace(['-', '_'], ' ', $value);

        return str_replace(' ', '', ucwords($normalized));
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
    public function filesFor(string $profile, Place $place, bool $overwrite = false, bool $minimal = false): array
    {
        $this->descriptor($profile);

        if ($minimal && ! in_array($profile, ['packman-module', 'packman-plugin'], true)) {
            throw new InvalidArgumentException('--minimal applies to packman-module and packman-plugin.');
        }

        $vars = $this->variablesFor($profile, $place);
        $base = $place->relativeDirectory;
        $composerStub = $minimal && $profile === 'packman-module' ? "{$profile}/minimal/composer.json.stub" : "{$profile}/composer.json.stub";

        $files = [
            new GeneratedFile("{$base}/composer.json", $this->stubs->render($composerStub, $vars), $overwrite),
            new GeneratedFile("{$base}/README.md", $this->stubs->render("{$profile}/README.md.stub", $vars), $overwrite),
            new GeneratedFile("{$base}/CHANGELOG.md", $this->stubs->render("{$profile}/CHANGELOG.md.stub", $vars), $overwrite),
        ];

        if ($minimal) {
            $type = $profile === 'packman-module' ? 'Module' : 'Plugin';
            $files[] = new GeneratedFile("{$base}/src/{$place->classPrefix}{$type}.php", $this->stubs->render("{$profile}/minimal/{$type}.php.stub", $vars), $overwrite);
            $files[] = new GeneratedFile("{$base}/tests/Feature/{$place->classPrefix}{$type}Test.php", $this->stubs->render("{$profile}/tests/Feature/{$type}ContractTest.php.stub", $vars), $overwrite);

            if ($profile === 'packman-plugin') {
                $files[] = new GeneratedFile("{$base}/src/Laravel/{$place->classPrefix}ServiceProvider.php", $this->stubs->render("{$profile}/minimal/ServiceProvider.php.stub", $vars), $overwrite);
            }

            return $files;
        }

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
