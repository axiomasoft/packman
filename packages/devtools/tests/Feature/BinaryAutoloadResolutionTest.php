<?php

declare(strict_types=1);

use Packman\DevTools\Tests\Support\TempWorkspace;
use Symfony\Component\Process\Process;

it('resolves $_composer_autoload_path first and consumer vendor/autoload.php in distributed bin/packman', function (): void {
    $repoRoot = dirname(__DIR__, 4);
    $repoAutoload = $repoRoot.'/vendor/autoload.php';
    $binarySource = (string) file_get_contents($repoRoot.'/packages/devtools/bin/packman');
    $ws = new TempWorkspace('packman-bin-autoload-');

    try {
        $ws->write('vendor/axiomasoft/packman-devtools/bin/packman', $binarySource);
        $ws->write('packman.json', json_encode([
            'schema_version' => 1,
            'name' => 'acme/consumer',
            'profile' => 'laravel-package',
            'packages' => [],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");

        // 1. Consumer vendor/autoload.php (dirname(__DIR__, 3).'/autoload.php')
        $ws->write('vendor/autoload.php', <<<PHP
<?php
file_put_contents(__DIR__ . '/../loaded-autoload.txt', 'consumer-vendor');
return require '{$repoAutoload}';
PHP);

        $consumerProc = new Process(
            [PHP_BINARY, $ws->path('vendor/axiomasoft/packman-devtools/bin/packman'), 'list', '--format=txt'],
            $ws->root,
        );
        $consumerProc->run();

        expect($consumerProc->isSuccessful())->toBeTrue($consumerProc->getErrorOutput().$consumerProc->getOutput())
            ->and($consumerProc->getOutput())->toContain('Packman DevTools')
            ->and(file_get_contents($ws->path('loaded-autoload.txt')))->toBe('consumer-vendor');

        // 2. Composer-supplied $_composer_autoload_path takes precedence over fallback paths
        $ws->write('custom-vendor/autoload.php', <<<PHP
<?php
file_put_contents(__DIR__ . '/../loaded-autoload.txt', 'composer-supplied');
return require '{$repoAutoload}';
PHP);
        $ws->write('vendor/bin/packman', <<<'PHP'
<?php
$_composer_autoload_path = dirname(__DIR__, 2) . '/custom-vendor/autoload.php';
return include dirname(__DIR__) . '/axiomasoft/packman-devtools/bin/packman';
PHP);

        $composerProc = new Process(
            [PHP_BINARY, $ws->path('vendor/bin/packman'), 'list', '--format=txt'],
            $ws->root,
        );
        $composerProc->run();

        expect($composerProc->isSuccessful())->toBeTrue($composerProc->getErrorOutput().$composerProc->getOutput())
            ->and($composerProc->getOutput())->toContain('Packman DevTools')
            ->and(file_get_contents($ws->path('loaded-autoload.txt')))->toBe('composer-supplied');
    } finally {
        $ws->cleanup();
    }
});
