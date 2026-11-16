<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Runner;

use Packman\Attributes\Api;
use Packman\DevTools\Data\Gates\MonorepoConfig;
use Packman\DevTools\Data\Gates\PackageManifest;

/**
 * Resolves package ownership of FQCN symbols and relative workspace paths using longest-prefix matching.
 */
#[Api(since: '1.0')]
final readonly class OwnersCatalogue
{
    /** @var list<PackageManifest> */
    private array $byNamespaceLength;

    public function __construct(MonorepoConfig $config)
    {
        $sorted = $config->packages;
        usort(
            $sorted,
            static fn (PackageManifest $a, PackageManifest $b): int => strlen($b->normalizedNamespace()) <=> strlen($a->normalizedNamespace()),
        );

        $this->byNamespaceLength = $sorted;
    }

    public function ownerOfClass(string $fqcn): ?PackageManifest
    {
        $clean = ltrim($fqcn, '\\');
        foreach ($this->byNamespaceLength as $package) {
            $prefix = $package->normalizedNamespace();

            if ($prefix !== '\\' && str_starts_with($clean, $prefix)) {
                return $package;
            }
        }

        return null;
    }

    public function ownerOfRelativePath(string $relativePath): ?PackageManifest
    {
        $clean = trim($relativePath, '/');
        foreach ($this->byNamespaceLength as $package) {
            $prefix = trim($package->path, '/').'/';

            if (str_starts_with($clean.'/', $prefix)) {
                return $package;
            }
        }

        return null;
    }
}
