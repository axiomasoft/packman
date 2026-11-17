<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Runner;

use Packman\Attributes\Api;
use Packman\DevTools\Data\Gates\MonorepoConfig;
use Packman\DevTools\Data\Gates\PackageManifest;
use RuntimeException;

/** Reads and normalizes packman.json and schemas/profiles/*.profile.json manifests. */
#[Api(since: '1.0')]
final class PackmanManifestReader
{
    public function read(string $rootPath): MonorepoConfig
    {
        $root = rtrim($rootPath, '/');
        $manifestPath = $root.'/packman.json';

        if (! is_file($manifestPath)) {
            throw new RuntimeException("Missing monorepo manifest: {$manifestPath}");
        }

        $raw = file_get_contents($manifestPath);

        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException("Unreadable monorepo manifest: {$manifestPath}");
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException("Invalid JSON root in {$manifestPath}");
        }

        $packages = [];
        $rawPackages = $data['packages'] ?? [];

        if (is_array($rawPackages)) {
            foreach ($rawPackages as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $rawOwns = is_array($entry['owns'] ?? null) ? $entry['owns'] : [];
                $owns = array_values(array_filter($rawOwns, is_string(...)));

                $packages[] = new PackageManifest(
                    name: is_string($entry['name'] ?? null) ? $entry['name'] : '',
                    path: is_string($entry['path'] ?? null) ? $entry['path'] : '',
                    namespace: is_string($entry['namespace'] ?? null) ? $entry['namespace'] : '',
                    profile: is_string($entry['profile'] ?? null) ? $entry['profile'] : 'laravel-package',
                    layer: is_string($entry['layer'] ?? null) ? $entry['layer'] : 'L2',
                    type: is_string($entry['type'] ?? null) ? $entry['type'] : 'addon',
                    devOnly: (bool) ($entry['dev_only'] ?? false),
                    owns: $owns,
                );
            }
        }

        $profiles = [];
        $bundledDir = dirname(__DIR__, 3).'/resources/schemas/profiles';
        $profileGlobs = [
            $bundledDir.'/*.profile.json',
            $root.'/schemas/profiles/*.profile.json',
        ];
        foreach ($profileGlobs as $globPattern) {
            $rawProfileFiles = glob($globPattern);
            $profileFiles = is_array($rawProfileFiles) ? $rawProfileFiles : [];
            sort($profileFiles, SORT_STRING);
            foreach ($profileFiles as $profileFile) {
                $profileRaw = file_get_contents($profileFile);

                if (! is_string($profileRaw) || $profileRaw === '') {
                    continue;
                }

                $decodedProfile = json_decode($profileRaw, true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($decodedProfile)) {
                    continue;
                }

                $profileName = is_string($decodedProfile['profile'] ?? null)
                    ? $decodedProfile['profile']
                    : basename($profileFile, '.profile.json');

                /** @var array<string, mixed> $decodedProfile */
                $profiles[$profileName] = $decodedProfile;
            }
        }

        $gates = is_array($data['gates'] ?? null) ? $data['gates'] : [];
        /** @var array<string, mixed> $typedGates */
        $typedGates = $gates;

        $providerLimit = $data['provider_max_lines'] ?? $typedGates['provider_size_limit'] ?? 80;

        return new MonorepoConfig(
            name: is_string($data['name'] ?? null) ? $data['name'] : 'monorepo',
            profile: is_string($data['profile'] ?? null) ? $data['profile'] : 'modular-platform',
            php: is_string($data['php'] ?? null) ? $data['php'] : '^8.4',
            phpstanLevel: is_string($data['phpstan_level'] ?? null) ? $data['phpstan_level'] : 'max',
            providerMaxLines: is_int($providerLimit) ? $providerLimit : 80,
            packages: $packages,
            gates: $typedGates,
            profiles: $profiles,
        );
    }
}
