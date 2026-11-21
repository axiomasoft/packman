<?php

declare(strict_types=1);

namespace Packman\DevTools\Data\Gates;

use JsonException;
use Packman\Attributes\Api;
use Packman\DevTools\Gates\Runner\OwnersCatalogue;
use RuntimeException;

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
            throw new RuntimeException("Cannot read {$path}: composer.json is empty or unreadable.");
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Cannot read {$path}: ".$exception->getMessage(), $exception->getCode(), $exception);
        }

        if (! is_array($decoded) || ! str_starts_with(ltrim($raw), '{')) {
            throw new RuntimeException("Cannot read {$path}: composer.json must be a JSON object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
