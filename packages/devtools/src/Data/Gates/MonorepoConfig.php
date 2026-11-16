<?php

declare(strict_types=1);

namespace Packman\DevTools\Data\Gates;

use Packman\Attributes\Api;

/** Parsed monorepo configuration loaded from packman.json and schemas/profiles/*.profile.json. */
#[Api(since: '1.0')]
final readonly class MonorepoConfig
{
    /**
     * @param  list<PackageManifest>  $packages
     * @param  array<string, mixed>  $gates
     * @param  array<string, array<string, mixed>>  $profiles
     */
    public function __construct(
        public string $name,
        public string $profile,
        public string $php,
        public string $phpstanLevel,
        public int $providerMaxLines,
        public array $packages,
        public array $gates,
        public array $profiles,
    ) {}

    public function errorUrnPrefix(): string
    {
        $prefix = $this->gates['error_urn_prefix'] ?? 'urn:packman:problem:';

        return is_string($prefix) && $prefix !== '' ? $prefix : 'urn:packman:problem:';
    }

    public function disallowInternalCrossImports(): bool
    {
        return (bool) ($this->gates['disallow_internal_cross_imports'] ?? true);
    }

    /** @return ?array<string, mixed> */
    public function profileConfig(string $profileName): ?array
    {
        return $this->profiles[$profileName] ?? null;
    }

    public function findPackageByName(string $name): ?PackageManifest
    {
        foreach ($this->packages as $package) {
            if ($package->name === $name) {
                return $package;
            }
        }

        return null;
    }
}
