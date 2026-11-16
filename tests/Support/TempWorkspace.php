<?php

declare(strict_types=1);

namespace Packman\DevTools\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Isolated temporary workspace builder for DevTools positive/negative gate and CLI tests. */
final class TempWorkspace
{
    public readonly string $root;

    public function __construct(string $prefix = 'packman-devtools-test-')
    {
        $this->root = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->root : $this->root.'/'.ltrim($relative, '/');
    }

    public function write(string $relativePath, string $contents): string
    {
        $target = $this->path($relativePath);
        $dir = dirname($target);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($target, $contents);

        return $target;
    }

    /** @param array<string, mixed> $data */
    public function writeJson(string $relativePath, array $data): string
    {
        return $this->write(
            $relativePath,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    public function seedSchemasFromRepo(string $repoRoot): void
    {
        foreach (glob(rtrim($repoRoot, '/').'/schemas/*.json') ?: [] as $file) {
            $this->write('schemas/'.basename($file), (string) file_get_contents($file));
        }

        foreach (glob(rtrim($repoRoot, '/').'/schemas/profiles/*.json') ?: [] as $file) {
            $this->write('schemas/profiles/'.basename($file), (string) file_get_contents($file));
        }
    }

    /** @param list<array<string, mixed>> $packages */
    public function writePackmanJson(array $packages): string
    {
        return $this->writeJson('packman.json', [
            'schema_version' => 1,
            'name' => 'acme/monorepo',
            'profile' => 'modular-platform',
            'php' => '^8.4',
            'phpstan_level' => 'max',
            'provider_max_lines' => 80,
            'packages' => $packages,
            'gates' => [
                'strict_ownership' => true,
                'provider_size_limit' => 80,
                'disallow_internal_cross_imports' => true,
                'require_api_manifest' => true,
                'error_urn_prefix' => 'urn:packman:problem:',
            ],
        ]);
    }

    /**
     * @return array{name: string, path: string, namespace: string, profile: string, layer: string, type: string, dev_only: bool}
     */
    public function seedCleanMonorepoPackage(
        string $composerName = 'acme/sample',
        string $path = 'packages/sample',
        string $namespace = 'Acme\\Sample\\',
        string $profile = 'laravel-package',
        string $layer = 'L2',
        bool $devOnly = false,
    ): array {
        $this->writeJson("{$path}/composer.json", [
            'name' => $composerName,
            'description' => "Sample {$composerName} package.",
            'type' => 'library',
            'license' => 'MIT',
            'require' => ['php' => '^8.4'],
            'autoload' => ['psr-4' => [$namespace => 'src/']],
        ]);
        $this->write("{$path}/README.md", "# {$composerName}\n");
        $this->write("{$path}/CHANGELOG.md", "# Changelog\n\n## Unreleased\n- Initial.\n");

        $nsClean = trim($namespace, '\\');
        $this->write("{$path}/src/Contracts/SampleService.php", <<<PHP
<?php

declare(strict_types=1);

namespace {$nsClean}\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SampleService
{
    public function ping(): string;
}

PHP);

        return [
            'name' => $composerName,
            'path' => $path,
            'namespace' => $namespace,
            'profile' => $profile,
            'layer' => $layer,
            'type' => $devOnly ? 'dev' : 'addon',
            'dev_only' => $devOnly,
        ];
    }

    public function cleanup(): void
    {
        if (! is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
        }

        @rmdir($this->root);
    }
}
