<?php

declare(strict_types=1);

namespace Packman\DevTools\Data\Gates;

use Packman\Attributes\Api;

/** Normalized package entry from packman.json. */
#[Api(since: '1.0')]
final readonly class PackageManifest
{
    public function __construct(
        public string $name,
        public string $path,
        public string $namespace,
        public string $profile,
        public string $layer,
        public string $type,
        public bool $devOnly = false,
    ) {}

    public function layerRank(): int
    {
        if (preg_match('/^L(\d+)$/i', $this->layer, $matches) === 1) {
            return (int) $matches[1];
        }

        return 99;
    }

    public function normalizedNamespace(): string
    {
        return trim($this->namespace, '\\').'\\';
    }
}
