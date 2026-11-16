<?php

declare(strict_types=1);

namespace Packman\DevTools\Generators\Contracts;

use Packman\Attributes\Api;
use Packman\DevTools\Scanners\Surface\PhpApiDocument;
use Packman\DevTools\Scanners\Surface\SurfaceScanner;

/** Checks or writes resources/contracts/php-api.json manifests across packages. */
#[Api(since: '1.0')]
final readonly class ApiManifestGenerator
{
    public function __construct(
        private SurfaceScanner $scanner = new SurfaceScanner,
    ) {}

    /**
     * @param  list<array{name: string, path: string}>  $packages
     * @return array{ok: bool, diagnostics: list<string>, changed: list<string>}
     */
    public function sync(string $rootPath, array $packages, bool $write): array
    {
        $diagnostics = [];
        $changed = [];
        $schemaPath = rtrim($rootPath, '/').'/schemas/php-api.schema.json';

        foreach ($packages as $pkg) {
            $packageDir = rtrim($rootPath, '/').'/'.trim($pkg['path'], '/');
            $srcDir = $packageDir.'/src';
            $report = $this->scanner->scanPackage($pkg['name'], $srcDir);

            foreach ($report->diagnostics as $diag) {
                $diagnostics[] = $diag->format();
            }

            if (! $report->ok()) {
                continue;
            }

            $encoded = PhpApiDocument::encode($report);

            if (is_file($schemaPath)) {
                $schemaErrors = PhpApiDocument::validateJsonAgainstSchema($encoded, $schemaPath);
                foreach ($schemaErrors as $error) {
                    $diagnostics[] = "[{$pkg['name']}] {$error}";
                }

                if ($schemaErrors !== []) {
                    continue;
                }
            }

            $manifestPath = $packageDir.'/resources/contracts/php-api.json';
            $existing = is_file($manifestPath) ? file_get_contents($manifestPath) : null;

            if ($existing === $encoded) {
                continue;
            }

            if ($write) {
                $dir = dirname($manifestPath);

                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }

                file_put_contents($manifestPath, $encoded);
                $changed[] = $pkg['path'].'/resources/contracts/php-api.json';
            } else {
                $diagnostics[] = sprintf(
                    '[%s] Out-of-date API manifest: %s/resources/contracts/php-api.json (run `php bin/packman php-api --write`).',
                    $pkg['name'],
                    $pkg['path'],
                );
            }
        }

        return [
            'ok' => $diagnostics === [],
            'diagnostics' => $diagnostics,
            'changed' => $changed,
        ];
    }
}
