<?php

declare(strict_types=1);

use Packman\DevTools\Console\ApplicationFactory;
use Packman\DevTools\Scaffold\Layout;
use Packman\DevTools\Scaffold\Place;
use Packman\DevTools\Scaffold\ProviderRegistration;
use Packman\DevTools\Scaffold\StubStore;
use Packman\DevTools\Tests\Support\TempWorkspace;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

it('fails loudly when StubStore leaves unresolved placeholders and formats Pint-ordered imports', function (): void {
    $ws = new TempWorkspace('packman-stubstore-');

    try {
        $ws->write('stubs/sample.stub', 'Hello {{ resolved }} and {{ missing }}!');
        $store = new StubStore($ws->path('stubs'));

        expect(static fn () => $store->render('sample.stub', ['resolved' => 'world']))
            ->toThrow(RuntimeException::class, 'Unresolved placeholder');

        expect(StubStore::imports(['Zeta\\Bar', 'Alpha\\Foo', 'Zeta\\Bar']))
            ->toBe("use Alpha\\Foo;\nuse Zeta\\Bar;");

        $updatedComposer = ProviderRegistration::registerInComposerJson(
            "{\n  \"name\": \"acme/root\"\n}\n",
            Place::fromInput('axiomasoft/packman-billing'),
        );
        expect($updatedComposer)->toContain('Packman\\\\Billing\\\\');
    } finally {
        $ws->cleanup();
    }
});

it('scaffolds valid PHP files for all 4 profiles via MakeCommand CLI', function (string $profile, string $expectedFile): void {
    $ws = new TempWorkspace('packman-make-'.$profile.'-');

    try {
        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('make'));

        $exit = $tester->execute([
            'profile' => $profile,
            'name' => 'acme/sample-'.$profile,
            '--root' => $ws->root,
        ]);

        expect($exit)->toBe(0, $tester->getDisplay())
            ->and(is_file($ws->path($expectedFile)))->toBeTrue();

        // Check every generated PHP file compiles cleanly with php -l
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ws->root));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $lint = new Process([PHP_BINARY, '-l', $file->getPathname()]);
                $lint->run();
                expect($lint->isSuccessful())->toBeTrue($lint->getErrorOutput());
            }
        }

        // Negative: re-running without --force fails
        expect($tester->execute([
            'profile' => $profile,
            'name' => 'acme/sample-'.$profile,
            '--root' => $ws->root,
        ]))->toBe(1);
    } finally {
        $ws->cleanup();
    }
})->with([
    'php-library' => ['php-library', 'packages/sample-php-library/src/Contracts/SamplePhpLibraryContract.php'],
    'laravel-package' => ['laravel-package', 'packages/sample-laravel-package/src/Laravel/SampleLaravelPackageServiceProvider.php'],
    'packman-plugin' => ['packman-plugin', 'packages/sample-packman-plugin/src/SamplePackmanPluginPlugin.php'],
    'packman-module' => ['packman-module', 'packages/sample-packman-module/src/SamplePackmanModuleModule.php'],
]);

it('verifies generated packman-plugin and packman-module implement expected Packman contracts and rejects unknown profile', function (): void {
    $ws = new TempWorkspace('packman-make-contracts-');

    try {
        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('make'));

        expect($tester->execute(['profile' => 'unknown-profile', 'name' => 'acme/x', '--root' => $ws->root]))->toBe(1);

        $tester->execute(['profile' => 'packman-plugin', 'name' => 'acme/webhooks', '--root' => $ws->root]);
        $pluginCode = (string) file_get_contents($ws->path('packages/webhooks/src/WebhooksPlugin.php'));
        expect($pluginCode)
            ->toContain('implements ExtensionPlugin, WithConfiguration')
            ->toContain('use Configurable;')
            ->toContain('function configRoot(): string');

        $tester->execute(['profile' => 'packman-module', 'name' => 'acme/ledger', '--root' => $ws->root]);
        $moduleCode = (string) file_get_contents($ws->path('packages/ledger/src/LedgerModule.php'));
        expect($moduleCode)
            ->toContain('extends ModuleDefinition implements WithConfiguration, WithDiagnostics, WithSchema')
            ->toContain('use Configurable;')
            ->toContain('use OwnsSchema;')
            ->toContain('use ProvidesDiagnostics;');
        expect(Layout::PROFILES)->toHaveCount(4);
    } finally {
        $ws->cleanup();
    }
});
