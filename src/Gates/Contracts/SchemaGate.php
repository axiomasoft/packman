<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Contracts;

use JsonException;
use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Internal\Schemas\JsonSchemaValidator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Validates JSON schemas, packman.json against packman.schema.json, and monotonic migration timestamps.
 */
#[Api(since: '1.0')]
final class SchemaGate implements QualityGate
{
    public function id(): string
    {
        return 'schema-gate';
    }

    public function category(): string
    {
        return 'contracts';
    }

    public function description(): string
    {
        return 'Validates packman.json schema compliance, JSON schema syntax integrity, and migration timestamp uniqueness.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $root = rtrim($context->rootPath, '/');

        $manifestPath = $root.'/packman.json';
        $manifestSchemaPath = $root.'/schemas/packman.schema.json';

        if (is_file($manifestPath) && is_file($manifestSchemaPath)) {
            $manifestRaw = file_get_contents($manifestPath);

            if (! is_string($manifestRaw)) {
                $violations[] = 'packman.json: unable to read manifest.';
            } else {
                foreach (JsonSchemaValidator::validateJsonAgainstFile($manifestRaw, $manifestSchemaPath) as $err) {
                    $violations[] = "packman.json: {$err}";
                }
            }
        }

        foreach ($this->collectJsonFiles($root.'/schemas') as $jsonFile) {
            $this->assertValidJsonSyntax($jsonFile, $root, $violations);
        }

        foreach ($context->config->packages as $package) {
            $pkgDir = $context->packageDirectory($package);
            $composerPath = $pkgDir.'/composer.json';

            if (is_file($composerPath)) {
                $this->assertValidJsonSyntax($composerPath, $root, $violations);
            }

            foreach ($this->collectJsonFiles($pkgDir.'/resources') as $resourceJson) {
                $this->assertValidJsonSyntax($resourceJson, $root, $violations);
            }

            $migrationsDir = $pkgDir.'/database/migrations';

            if (is_dir($migrationsDir)) {
                $seenPrefixes = [];
                $rawFiles = glob($migrationsDir.'/*.php');
                $files = is_array($rawFiles) ? $rawFiles : [];
                sort($files, SORT_STRING);
                foreach ($files as $migrationFile) {
                    $base = basename($migrationFile);

                    if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_([a-z0-9_]+)\.php$/', $base, $matches) !== 1) {
                        $violations[] = "[{$package->name}] Migration filename does not follow YYYY_MM_DD_NNNNNN_name.php: {$base}";

                        continue;
                    }

                    $prefix = $matches[1];

                    if (isset($seenPrefixes[$prefix])) {
                        $violations[] = "[{$package->name}] Duplicate migration timestamp prefix {$prefix} ({$seenPrefixes[$prefix]} and {$base}).";
                    }

                    $seenPrefixes[$prefix] = $base;
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), ['Monorepo schemas, JSON contracts, and migration timestamps are valid.'])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }

    /** @return list<string> */
    private function collectJsonFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'json') {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /** @param list<string> $violations */
    private function assertValidJsonSyntax(string $path, string $root, array &$violations): void
    {
        $raw = file_get_contents($path);

        if (! is_string($raw) || trim($raw) === '') {
            $violations[] = 'Empty or unreadable JSON file: '.ltrim(str_replace($root, '', $path), '/');

            return;
        }

        try {
            json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $rel = ltrim(str_replace($root, '', $path), '/');
            $violations[] = sprintf('%s: invalid JSON (%s)', $rel, $exception->getMessage());
        }
    }
}
