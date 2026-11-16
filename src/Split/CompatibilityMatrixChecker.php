<?php

declare(strict_types=1);

namespace Packman\DevTools\Split;

use Packman\Attributes\Api;
use Packman\DevTools\Gates\Runner\PackmanManifestReader;

/**
 * Verifies package composer.json constraints satisfy root compatibility.json PHP/Laravel matrix declarations.
 */
#[Api(since: '1.0')]
final readonly class CompatibilityMatrixChecker
{
    public function __construct(
        private PackmanManifestReader $manifestReader = new PackmanManifestReader,
    ) {}

    /** @return array{ok: bool, diagnostics: list<string>, checked: int} */
    public function check(string $rootPath): array
    {
        $root = rtrim($rootPath, '/');
        $matrixPath = $root.'/compatibility.json';

        if (! is_file($matrixPath)) {
            return ['ok' => false, 'diagnostics' => ["Missing compatibility matrix file: {$matrixPath}"], 'checked' => 0];
        }

        $raw = file_get_contents($matrixPath);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            return ['ok' => false, 'diagnostics' => ['Invalid JSON in compatibility.json'], 'checked' => 0];
        }

        $config = $this->manifestReader->read($root);
        $diagnostics = [];
        $checked = 0;

        $expectedPhp = is_string($decoded['php'] ?? null)
            ? $decoded['php']
            : (is_array($decoded['php'] ?? null) ? implode('|', array_map(static fn (mixed $v): string => (is_string($v) || is_int($v)) ? (string) $v : '', $decoded['php'])) : $config->php);

        foreach ($config->packages as $package) {
            $checked++;
            $composerPath = $root.'/'.trim($package->path, '/').'/composer.json';

            if (! is_file($composerPath)) {
                $diagnostics[] = "[{$package->name}] Missing composer.json at {$package->path}/composer.json.";

                continue;
            }

            $pkgRaw = file_get_contents($composerPath);
            $pkgComposer = is_string($pkgRaw) ? json_decode($pkgRaw, true) : null;

            if (! is_array($pkgComposer)) {
                $diagnostics[] = "[{$package->name}] Invalid composer.json syntax.";

                continue;
            }

            $require = is_array($pkgComposer['require'] ?? null) ? $pkgComposer['require'] : [];
            $phpConstraint = $require['php'] ?? null;

            if (! is_string($phpConstraint) || $phpConstraint === '') {
                $diagnostics[] = "[{$package->name}] Missing require.php constraint (expected compatibility with {$expectedPhp}).";
            }
        }

        $artifacts = $decoded['artifacts'] ?? null;

        if (is_array($artifacts)) {
            foreach ($artifacts as $artifact) {
                if (! is_array($artifact)) {
                    continue;
                }

                $artPath = is_string($artifact['path'] ?? null) ? $artifact['path'] : '';

                if ($artPath === '') {
                    continue;
                }

                $checked++;
                $fullArt = $root.'/'.ltrim($artPath, '/');

                if (! is_file($fullArt)) {
                    $diagnostics[] = "Compatibility artifact missing: {$artPath}";

                    continue;
                }

                $artRaw = file_get_contents($fullArt);
                $artDecoded = is_string($artRaw) ? json_decode($artRaw, true) : null;

                if (! is_array($artDecoded)) {
                    $diagnostics[] = "Compatibility artifact contains invalid JSON: {$artPath}";
                }
            }
        }

        return [
            'ok' => $diagnostics === [],
            'diagnostics' => $diagnostics,
            'checked' => $checked,
        ];
    }
}
