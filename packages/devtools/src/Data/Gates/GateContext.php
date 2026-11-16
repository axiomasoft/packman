<?php

declare(strict_types=1);

namespace Packman\DevTools\Data\Gates;

use Packman\Attributes\Api;
use Packman\DevTools\Gates\Runner\OwnersCatalogue;

/** Shared execution context passed to every QualityGate run. */
#[Api(since: '1.0')]
final readonly class GateContext
{
    public function __construct(
        public string $rootPath,
        public MonorepoConfig $config,
        public OwnersCatalogue $owners,
    ) {}

    public function packageDirectory(PackageManifest $package): string
    {
        return rtrim($this->rootPath, '/').'/'.trim($package->path, '/');
    }

    /** @return array<string, mixed> */
    public function readComposerJson(PackageManifest $package): array
    {
        $path = $this->packageDirectory($package).'/composer.json';

        if (! is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
