<?php

declare(strict_types=1);

namespace Packman\DevTools\Internal;

use Closure;
use Composer\Autoload\ClassLoader;
use Packman\Attributes\Internal;

/** Exposes the inspected Composer project's classes for the duration of a CLI operation. */
#[Internal]
final class ProjectAutoload
{
    /**
     * @template T
     *
     * @param  Closure(): T  $inspect
     * @return T
     */
    public static function run(string $rootPath, Closure $inspect): mixed
    {
        $vendor = self::vendorDirectory($rootPath);

        if ($vendor === false || isset(ClassLoader::getRegisteredLoaders()[$vendor])) {
            return $inspect();
        }

        // Append the target's maps so the CLI keeps its own dependency versions.
        $loader = new ClassLoader($vendor);
        $maps = $vendor.'/composer';

        if (is_file($maps.'/autoload_classmap.php')) {
            /** @var array<class-string, string> $classMap */
            $classMap = require $maps.'/autoload_classmap.php';
            $loader->addClassMap($classMap);
        }

        if (is_file($maps.'/autoload_psr4.php')) {
            /** @var array<string, list<string>> $prefixes */
            $prefixes = require $maps.'/autoload_psr4.php';
            foreach ($prefixes as $prefix => $dirs) {
                $loader->addPsr4($prefix, $dirs);
            }
        }

        if (is_file($maps.'/autoload_namespaces.php')) {
            /** @var array<string, list<string>> $namespaces */
            $namespaces = require $maps.'/autoload_namespaces.php';
            foreach ($namespaces as $prefix => $dirs) {
                $loader->add($prefix, $dirs);
            }
        }

        $loader->register();

        try {
            return $inspect();
        } finally {
            $loader->unregister();
        }
    }

    private static function vendorDirectory(string $rootPath): string|false
    {
        $directory = getenv('COMPOSER_VENDOR_DIR');

        if ($directory === false || $directory === '') {
            $manifest = $rootPath.'/composer.json';
            $contents = is_file($manifest) ? file_get_contents($manifest) : false;
            $composer = is_string($contents) ? json_decode($contents, true) : null;
            $config = is_array($composer) ? ($composer['config'] ?? null) : null;
            $configured = is_array($config) ? ($config['vendor-dir'] ?? null) : null;
            $directory = is_string($configured) && $configured !== '' ? $configured : 'vendor';
        }

        // Resolve Composer's relative vendor-dir against the inspected project.
        $path = str_replace('\\', '/', $directory);
        $absolute = str_starts_with($path, '/') || preg_match('~^[a-z]:/~i', $path) === 1;

        return realpath($absolute ? $directory : $rootPath.'/'.$directory);
    }
}
