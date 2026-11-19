<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Compiler;

use Packman\Attributes\Internal;
use Packman\Modules\Data\Compiler\InstalledPackageVersion;

/**
 * The real composer dependency graph {@see RegistryCompiler::compile()}
 * checks `requires(ModuleDependency::module(...))` against by default. Reads the
 * standard `vendor/composer/installed.json` (Composer's own installed-set
 * manifest — no custom format, same file {@see Packman\Modules\Internal\Discovery\ModuleDiscovery} scans for
 * module/plugin packages). A missing/unreadable file resolves to an empty
 * list rather than failing — a deployment with zero third-party composer
 * packages is legal, and `requires` between manifests being compiled
 * together is still checked against each other regardless of this list.
 *
 * @internal spec: AC-2, B-10 §7.4 п.4
 */
#[Internal]
final class InstalledPackages
{
    /** @return list<string> */
    public static function discover(?string $installedJsonPath = null): array
    {
        $names = [];

        foreach (self::packages($installedJsonPath) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $names[] = $package['name'];
            }
        }

        return $names;
    }

    /**
     * Package name, version and Composer reference. Name-only {@see discover()} stays unchanged.
     *
     * Source reference wins over dist reference. A missing reference is explicit null.
     * Install path, timestamp, hostname, tenant and activation state are ignored.
     *
     * @return array<string, InstalledPackageVersion>
     */
    public static function discoverVersions(?string $installedJsonPath = null): array
    {
        $versions = [];

        foreach (self::packages($installedJsonPath) as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || trim($package['name']) === '') {
                continue;
            }

            $version = $package['version'] ?? null;

            if (! is_string($version) || trim($version) === '') {
                continue;
            }

            $name = $package['name'];

            if (isset($versions[$name])) {
                continue;
            }

            $versions[$name] = new InstalledPackageVersion(
                name: $name,
                version: $version,
                reference: self::reference($package),
            );
        }

        ksort($versions);

        return $versions;
    }

    /** @return list<mixed> */
    private static function packages(?string $installedJsonPath): array
    {
        $path = $installedJsonPath;

        if ($path === null || ! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            return [];
        }

        /** @var list<mixed> $packages */
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;

        return $packages;
    }

    /** @param array<array-key, mixed> $package */
    private static function reference(array $package): ?string
    {
        $source = self::nestedReference(package: $package, key: 'source');

        if ($source !== null) {
            return $source;
        }

        return self::nestedReference(package: $package, key: 'dist');
    }

    /** @param array<array-key, mixed> $package */
    private static function nestedReference(array $package, string $key): ?string
    {
        $node = $package[$key] ?? null;

        if (! is_array($node)) {
            return null;
        }

        $reference = $node['reference'] ?? null;

        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }

        return $reference;
    }
}
