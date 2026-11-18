<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Packman\DevTools\Console\ApplicationFactory;
use Packman\DevTools\Scaffold\GeneratedFile;
use Packman\DevTools\Scaffold\Layout;
use Packman\DevTools\Scaffold\Place;
use Packman\DevTools\Scaffold\ProviderRegistration;
use Packman\DevTools\Scaffold\ScaffoldCollisionException;
use Packman\DevTools\Scaffold\ScaffoldRunner;
use Packman\DevTools\Scaffold\StubStore;
use Packman\DevTools\Tests\Support\TempWorkspace;
use Packman\Extensions\ExtensionInspector;
use Packman\Modules\Contracts\Definition\ModuleDefinition;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
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

it('scaffolds valid PHP files and valid composer.json escaping for all 4 profiles via MakeCommand CLI', function (string $profile, string $expectedFile, string $packageDir, string $expectedNamespace, ?string $expectedProvider): void {
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

        $composer = json_decode((string) file_get_contents($ws->path("{$packageDir}/composer.json")), true, 512, JSON_THROW_ON_ERROR);
        expect($composer['autoload']['psr-4'] ?? [])->toBe([$expectedNamespace.'\\' => 'src/'])
            ->and($composer['autoload-dev']['psr-4'] ?? [])->toBe([$expectedNamespace.'\\Tests\\' => 'tests/']);

        if ($expectedProvider !== null) {
            expect($composer['extra']['laravel']['providers'] ?? [])->toBe([$expectedProvider])
                ->and($expectedProvider)->not->toContain('\\\\');
        }

        if ($profile === 'packman-module') {
            expect($composer['type'] ?? null)->toBe('packman-module')
                ->and($composer['extra']['packman']['module'] ?? null)->toBe($expectedNamespace.'\\SamplePackmanModuleModule');
        }

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
    'php-library' => ['php-library', 'packages/sample-php-library/src/Contracts/SamplePhpLibraryContract.php', 'packages/sample-php-library', 'Acme\\SamplePhpLibrary', null],
    'laravel-package' => ['laravel-package', 'packages/sample-laravel-package/src/Laravel/SampleLaravelPackageServiceProvider.php', 'packages/sample-laravel-package', 'Acme\\SampleLaravelPackage', 'Acme\\SampleLaravelPackage\\Laravel\\SampleLaravelPackageServiceProvider'],
    'packman-plugin' => ['packman-plugin', 'packages/sample-packman-plugin/src/SamplePackmanPluginPlugin.php', 'packages/sample-packman-plugin', 'Acme\\SamplePackmanPlugin', 'Acme\\SamplePackmanPlugin\\Laravel\\SamplePackmanPluginServiceProvider'],
    'packman-module' => ['packman-module', 'packages/sample-packman-module/src/SamplePackmanModuleModule.php', 'packages/sample-packman-module', 'Acme\\SamplePackmanModule', 'Acme\\SamplePackmanModule\\Laravel\\SamplePackmanModuleServiceProvider'],
]);

it('verifies generated packman-plugin and packman-module implement contracts, discover via ModuleDiscovery, and expose SchemaSetProvider', function (): void {
    $ws = new TempWorkspace('packman-make-contracts-');
    $autoloader = static function (string $class) use ($ws): void {
        $prefix = 'Acme\\Ledger\\';

        if (str_starts_with($class, $prefix)) {
            $file = $ws->path('packages/ledger/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php');

            if (is_file($file)) {
                require_once $file;
            }
        }
    };
    spl_autoload_register($autoloader);

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
        $providerCode = (string) file_get_contents($ws->path('packages/ledger/src/Laravel/LedgerServiceProvider.php'));
        $composer = json_decode((string) file_get_contents($ws->path('packages/ledger/composer.json')), true, 512, JSON_THROW_ON_ERROR);

        expect($moduleCode)
            ->toContain('extends ModuleDefinition implements WithConfiguration, WithDiagnostics, WithSchema')
            ->toContain('use Configurable;')
            ->toContain('use OwnsSchema;')
            ->toContain('use ProvidesDiagnostics;')
            ->and($providerCode)->not->toContain('loadMigrationsFrom')
            ->and($providerCode)->toContain('SchemaSetProvider::class')
            ->and(is_file($ws->path('packages/ledger/src/Schema/ModuleSchemaSetProvider.php')))->toBeTrue()
            ->and(is_file($ws->path('packages/ledger/database/migrations/tenant/2026_11_01_000001_create_le_records_table.php')))->toBeTrue()
            ->and(Layout::PROFILES)->toHaveCount(4);

        // Verify ModuleDiscovery discovers the scaffolded packman-module from Composer installed.json
        $ws->writeJson('vendor/composer/installed.json', ['packages' => [$composer]]);
        $manifests = (new ModuleDiscovery(installedJsonPath: $ws->path('vendor/composer/installed.json')))->discover();

        expect($manifests)->toHaveCount(1)
            ->and($manifests[0]->composerName)->toBe('acme/ledger')
            ->and($manifests[0]->tablePrefix)->toBe('le');

        $moduleClass = $composer['extra']['packman']['module'];
        $module = new $moduleClass;
        expect($module)->toBeInstanceOf(ModuleDefinition::class)
            ->and(ExtensionInspector::inspect($module)->schemaSetProviders)->toBe(['Acme\\Ledger\\Schema\\ModuleSchemaSetProvider']);

        // Verify ModuleSchemaSetProvider exposes valid Tenant/Module SchemaSet and ServiceProvider tags it
        $providerClass = 'Acme\\Ledger\\Schema\\ModuleSchemaSetProvider';
        $schemaProvider = new $providerClass;
        expect($schemaProvider)->toBeInstanceOf(SchemaSetProvider::class);
        $sets = $schemaProvider->sets();
        expect($sets)->toHaveCount(1)
            ->and($sets[0])->toBeInstanceOf(SchemaSet::class)
            ->and($sets[0]->package)->toBe('acme/ledger')
            ->and($sets[0]->scope)->toBe(SchemaScope::Tenant)
            ->and($sets[0]->lifecycle)->toBe(SchemaLifecycle::Module)
            ->and(is_dir($sets[0]->path))->toBeTrue();

        $container = new class extends Container
        {
            /** @var list<string> */
            public array $configPaths = [];

            public function configurationIsCached(): bool
            {
                return false;
            }
        };
        $container->instance('config', new Repository([]));
        $spClass = $composer['extra']['laravel']['providers'][0];
        $sp = new $spClass($container);
        $sp->register();

        $tagged = iterator_to_array($container->tagged(SchemaSetProvider::class));
        expect($tagged)->toHaveCount(1)
            ->and($tagged[0])->toBeInstanceOf($providerClass);
    } finally {
        spl_autoload_unregister($autoloader);
        $ws->cleanup();
    }
});

it('supports directory Place navigation, Layout helpers, PHP array registration, and atomic ScaffoldRunner rollback', function (): void {
    $ws = new TempWorkspace('packman-scaffold-helpers-');

    try {
        $rootPlace = Place::fromDirectory($ws->path('app/Guards'), 'App\\Guards');
        $subPlace = $rootPlace->in('Admin/Permissions/Sales');

        expect($subPlace->directory())->toBe($ws->path('app/Guards/Admin/Permissions/Sales'))
            ->and($subPlace->namespace)->toBe('App\\Guards\\Admin\\Permissions\\Sales')
            ->and($subPlace->file('OrderPermission'))->toBe($ws->path('app/Guards/Admin/Permissions/Sales/OrderPermission.php'))
            ->and($subPlace->fqcn('OrderPermission'))->toBe('App\\Guards\\Admin\\Permissions\\Sales\\OrderPermission')
            ->and(Layout::className('sales-desk', 'Source'))->toBe('SalesDeskSource')
            ->and(Layout::className('SalesDeskSource', 'Source'))->toBe('SalesDeskSource')
            ->and(Layout::segments('Sales/Orders'))->toBe(['Sales', 'Orders'])
            ->and(Layout::relative($ws->root, $ws->path('app/Guards/Admin')))->toBe('app/Guards/Admin')
            ->and(Layout::absolute($ws->root, 'app/Guards/Admin'))->toBe($ws->path('app/Guards/Admin'))
            ->and(StubStore::imports(['App\\Zebra', 'App\\A\\Beta', 'App\\AB']))->toBe("use App\\A\\Beta;\nuse App\\AB;\nuse App\\Zebra;");

        $configSource = "<?php\n\nreturn [\n    // Keep comment\n    'panels' => [\n        'providers' => [],\n    ],\n];\n";
        $inserted = ProviderRegistration::registerInPhpArray($configSource, ['panels', 'providers'], 'App\\Guards\\Admin\\AdminPanelProvider');
        expect($inserted)->toContain('// Keep comment')
            ->and($inserted)->toContain('App\\Guards\\Admin\\AdminPanelProvider::class,')
            ->and(ProviderRegistration::registerInPhpArray((string) $inserted, ['panels', 'providers'], 'App\\Guards\\Admin\\AdminPanelProvider'))->toBe($inserted)
            ->and(ProviderRegistration::registerInPhpArray($configSource, ['missing', 'providers'], 'App\\Foo'))->toBeNull();

        $ws->write('existing.php', 'original');
        chmod($ws->path('existing.php'), 0o640);
        mkdir($ws->path('dir-target.php'));

        $runner = new ScaffoldRunner;
        expect(static fn () => $runner->run($ws->root, static fn (): array => [
            new GeneratedFile('new.php', 'new'),
            new GeneratedFile('existing.php', 'overwrite-refused'),
        ]))->toThrow(ScaffoldCollisionException::class, 'existing.php already exists.')
            ->and(is_file($ws->path('new.php')))->toBeFalse()
            ->and(file_get_contents($ws->path('existing.php')))->toBe('original');

        expect(static fn () => $runner->write([
            ['target' => $ws->path('existing.php'), 'file' => new GeneratedFile('existing.php', 'replaced', overwrite: true)],
            ['target' => $ws->path('new.php'), 'file' => new GeneratedFile('new.php', 'created')],
            ['target' => $ws->path('dir-target.php'), 'file' => new GeneratedFile('dir-target.php', 'fails')],
        ]))->toThrow(RuntimeException::class, 'Unable to move scaffold file into place')
            ->and(file_get_contents($ws->path('existing.php')))->toBe('original')
            ->and(fileperms($ws->path('existing.php')) & 0o777)->toBe(0o640)
            ->and(is_file($ws->path('new.php')))->toBeFalse();
    } finally {
        $ws->cleanup();
    }
});
