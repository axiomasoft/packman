<?php

declare(strict_types=1);

namespace Packman\DevTools\Scaffold;

use Packman\Attributes\Api;

/** Value object resolving target relative paths, PSR-4 namespaces, composer names, and class names for MakeCommand. */
#[Api(since: '1.0')]
final readonly class Place
{
    public function __construct(
        public string $vendor,
        public string $slug,
        public string $composerName,
        public string $namespace,
        public string $classPrefix,
        public string $tablePrefix,
        public string $pluginId,
        public string $relativeDirectory,
    ) {}

    public static function fromInput(string $name, ?string $vendorOverride = null, ?string $baseDirectory = 'packages'): self
    {
        $clean = trim($name);

        if (str_contains($clean, '/')) {
            [$rawVendor, $rawSlug] = explode('/', $clean, 2);
            $vendor = self::kebab($vendorOverride ?? $rawVendor);
            $slug = self::kebab($rawSlug);
        } else {
            $vendor = self::kebab($vendorOverride ?? 'axiomasoft');
            $slug = self::kebab($clean);
        }

        $shortSlug = str_starts_with($slug, 'packman-') ? substr($slug, 8) : $slug;
        $studlySlug = self::studly($shortSlug);
        $studlyVendor = $vendor === 'axiomasoft' ? 'Packman' : self::studly($vendor);
        $namespace = $studlyVendor.'\\'.$studlySlug;
        $cleanedPrefix = preg_replace('/[^a-z]/', '', strtolower($shortSlug));
        $prefixLetters = is_string($cleanedPrefix) && $cleanedPrefix !== '' ? $cleanedPrefix : 'pm';
        $tablePrefix = substr($prefixLetters, 0, 2);

        if (strlen($tablePrefix) < 2) {
            $tablePrefix = str_pad($tablePrefix, 2, 'x');
        }

        $dirPrefix = $baseDirectory !== null && trim($baseDirectory, '/') !== ''
            ? trim($baseDirectory, '/').'/'
            : '';

        return new self(
            vendor: $vendor,
            slug: $slug,
            composerName: $vendor.'/'.$slug,
            namespace: $namespace,
            classPrefix: $studlySlug,
            tablePrefix: $tablePrefix,
            pluginId: "{$vendor}.{$slug}",
            relativeDirectory: $dirPrefix.$shortSlug,
        );
    }

    private static function kebab(string $value): string
    {
        $converted = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $value) ?? $value;
        $converted = preg_replace('/[^a-zA-Z0-9-]+/', '-', $converted) ?? $converted;
        $trimmed = trim(strtolower($converted), '-');

        return $trimmed !== '' ? $trimmed : 'package';
    }

    private static function studly(string $value): string
    {
        $split = preg_split('/[-_.\s]+/', $value);
        $words = is_array($split) ? $split : [$value];
        $parts = array_map(static fn (string $word): string => ucfirst(strtolower($word)), array_filter($words, static fn (string $w): bool => $w !== ''));
        $joined = implode('', $parts);

        return $joined !== '' ? $joined : 'Package';
    }
}
