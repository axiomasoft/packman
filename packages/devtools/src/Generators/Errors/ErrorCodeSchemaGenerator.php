<?php

declare(strict_types=1);

namespace Packman\DevTools\Generators\Errors;

use Packman\Attributes\Api;
use Packman\DevTools\Data\Errors\ErrorCodeEntry;
use Packman\DevTools\Scanners\Errors\ErrorSourceScanner;

/**
 * Checks or writes per-package resources/contracts/error-codes.json catalogues.
 */
#[Api(since: '1.0')]
final readonly class ErrorCodeSchemaGenerator
{
    public function __construct(
        private ErrorSourceScanner $scanner = new ErrorSourceScanner,
    ) {}

    /**
     * @param  list<array{name: string, path: string}>  $packages
     * @return array{ok: bool, diagnostics: list<string>, changed: list<string>}
     */
    public function sync(string $rootPath, array $packages, string $urnPrefix, bool $write): array
    {
        $report = $this->scanner->scanPackages($rootPath, $packages, $urnPrefix);
        $diagnostics = $report->diagnostics;
        $changed = [];

        /** @var array<string, list<ErrorCodeEntry>> $grouped */
        $grouped = [];
        foreach ($packages as $pkg) {
            $grouped[$pkg['name']] = [];
        }

        foreach ($report->entries as $entry) {
            $grouped[$entry->package][] = $entry;
        }

        foreach ($packages as $pkg) {
            $entries = $grouped[$pkg['name']] ?? [];
            $manifestPath = rtrim($rootPath, '/').'/'.trim($pkg['path'], '/').'/resources/contracts/error-codes.json';

            if ($entries === []) {
                if (is_file($manifestPath) && ! $write) {
                    $diagnostics[] = sprintf('[%s] Stale error-codes.json found for package with zero ErrorCode enums.', $pkg['name']);
                }

                continue;
            }

            $payload = [
                'schema' => 'packman-error-codes/1',
                'package' => $pkg['name'],
                'codes' => array_map(
                    static fn (ErrorCodeEntry $entry): array => $entry->toArray(),
                    $entries,
                ),
            ];

            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
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
                $changed[] = $pkg['path'].'/resources/contracts/error-codes.json';
            } else {
                $diagnostics[] = sprintf(
                    '[%s] Out-of-date error codes manifest: %s/resources/contracts/error-codes.json (run `php bin/packman error-codes --write`).',
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
