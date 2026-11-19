<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ScopeRef;
use Packman\Modules\Contracts\Definition\ModuleDiscovery as DiscoveryContract;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Contracts\Registry\ModuleStateReader;
use Packman\Modules\Data\Lifecycle\ModuleDependency;
use Packman\Modules\Data\Lifecycle\ModuleManifest;
use Packman\Modules\Data\Settings\DefaultSettingDefinition;
use Packman\Modules\Exceptions\Compiler\InvalidManifestException;
use Packman\Modules\Internal\Commands\CompileModulesCommand;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Modules\Internal\Support\ModulesOptions;
use Packman\Modules\ModulesServiceProvider;
use Packman\Modules\Tests\Stubs\StubModuleDefinition;

beforeEach(function (): void {
    $this->app->register(ModulesServiceProvider::class);
});

it('replaces scoped discovery lists and merges named cache options', function (): void {
    config()->set('modules.cache.prefix', 'host');
    config()->set('packman.modules', [
        'package_types' => ['host-module'],
        'extra_keys' => [['definition']],
        'cache' => ['ttl' => 15],
    ]);
    $this->app->forgetInstance(ModulesOptions::class);

    $options = $this->app->make(ModulesOptions::class);

    expect($options->packageTypes)->toBe(['host-module'])
        ->and($options->extraKeys)->toBe([['definition']])
        ->and($options->cachePrefix)->toBe('host')
        ->and($options->cacheTtl)->toBe(15);
});

it('builds a fluent manifest with readable fields', function (): void {
    $manifest = ModuleManifest::make('packman/stub-module')
        ->title('Stub Module')
        ->description('A stub for tests.')
        ->tablePrefix('stb')
        ->requires([ModuleDependency::module('axiomasoft/packman-modules')])
        ->editions(['standard' => [], 'pro' => ['advanced-reports']])
        ->settings([DefaultSettingDefinition::make('greeting', 'hello')])
        ->listens(['App\\Events\\Foo' => ['App\\Listeners\\Bar']])
        ->onEnable('App\\Hooks\\EnableStub');

    expect($manifest->composerName)->toBe('packman/stub-module')
        ->and($manifest->title)->toBe('Stub Module')
        ->and($manifest->description)->toBe('A stub for tests.')
        ->and($manifest->tablePrefix)->toBe('stb')
        ->and($manifest->requires)->toHaveCount(1)
        ->and($manifest->requires[0]->kind)->toBe('module')
        ->and($manifest->requires[0]->package)->toBe('axiomasoft/packman-modules')
        ->and($manifest->editions)->toBe(['standard' => [], 'pro' => ['advanced-reports']])
        ->and($manifest->settings[0]->key)->toBe('greeting')
        ->and($manifest->listeners)->toBe(['App\\Events\\Foo' => ['App\\Listeners\\Bar']])
        ->and($manifest->onEnableHook)->toBe('App\\Hooks\\EnableStub');
});

it('rejects an invalid tablePrefix with a precise message', function (): void {
    expect(fn () => ModuleManifest::make('packman/stub-module')->tablePrefix('TOOLONG'))
        ->toThrow(InvalidManifestException::class, 'table prefix [TOOLONG] is invalid');
});

it('rejects an invalid composer name', function (): void {
    expect(fn () => ModuleManifest::make('Not A Valid Name'))
        ->toThrow(InvalidManifestException::class);
});

it('rejects an empty title', function (): void {
    expect(fn () => ModuleManifest::make('packman/stub-module')->title(''))
        ->toThrow(InvalidManifestException::class);
});

it('discovers manifests from an injected fallback list', function (): void {
    $discovery = new ModuleDiscovery(fallbackModules: [StubModuleDefinition::class]);

    $manifests = $discovery->discover();

    expect($manifests)->toHaveCount(1)
        ->and($manifests[0])->toBeInstanceOf(ModuleManifest::class)
        ->and($manifests[0]->composerName)->toBe('packman/stub-module')
        ->and($manifests[0]->tablePrefix)->toBe('stb');
});

it('rejects a discovered class that does not extend ModuleDefinition', function (): void {
    $discovery = new ModuleDiscovery(fallbackModules: [stdClass::class]);

    expect(fn () => $discovery->discover())->toThrow(InvalidManifestException::class);
});

it('skips packman-module packages without a module class but rejects an invalid one', function (mixed $module, ?string $error): void {
    $extra = ['schema-version' => 1];

    if ($module !== 'absent') {
        $extra['module'] = $module;
    }

    $installedJson = (string) tempnam(sys_get_temp_dir(), 'packman-modules-');
    file_put_contents($installedJson, json_encode(['packages' => [['name' => 'acme/lib', 'type' => 'packman-module', 'extra' => ['packman' => $extra]]]]));

    try {
        $discover = fn (): array => new ModuleDiscovery(installedJsonPath: $installedJson)->discover();

        if ($error === null) {
            expect($discover())->toBe([]);
        } else {
            expect($discover)->toThrow(InvalidManifestException::class, $error);
        }
    } finally {
        unlink($installedJson);
    }
})->with([
    'absent module' => ['absent', null],
    'empty string' => ['', 'Discovered module class []'],
    'non-string' => [['Packman\\Nope'], 'Discovered module class [array]'],
]);

it('compiles and activates a code-only module using host bindings without SQL', function (): void {
    $path = 'bootstrap/cache/module-'.bin2hex(random_bytes(6)).'.php';
    config()->set('modules.cache_path', $path);
    config()->set('modules.connection', 'unconfigured-connection');
    config()->set('modules.cache.store', 'unconfigured-store');
    $this->app->forgetInstance(ModulesOptions::class);
    $this->app->forgetInstance(ModuleRegistry::class);
    $discovery = new class implements DiscoveryContract
    {
        public function discover(): array
        {
            return [ModuleManifest::make('acme/code')->title('Code')];
        }
    };
    $reader = new class implements ModuleStateReader
    {
        public bool $enabled = true;

        public function enabledFor(ContextScopeInterface $ctx): array
        {
            return $this->enabled ? ['acme/code' => ['state' => 'enabled', 'edition' => 'standard']] : [];
        }
    };
    $this->app->instance(DiscoveryContract::class, $discovery);
    $this->app->instance(ModuleStateReader::class, $reader);

    try {
        expect(Artisan::call('modules:compile'))->toBe(0);
        $registry = $this->app->make(ModuleRegistry::class);
        $scope = new ScopeRef('example');
        expect($registry->get('acme/code')->tablePrefix)->toBeNull()
            ->and($registry->isActive('acme/code', $scope))->toBeTrue();
        $nextReader = clone $reader;
        $nextReader->enabled = false;
        $this->app->instance(ModuleStateReader::class, $nextReader);
        expect($registry->active($scope))->toBe([]);
    } finally {
        $file = $this->app->basePath($path);

        if (is_file($file)) {
            unlink($file);
        }
    }
});

it('modules:compile does not fail when discovery reports no module packages', function (): void {
    $installedJson = tempnam(sys_get_temp_dir(), 'packman-modules-');
    file_put_contents($installedJson, '{"packages":[]}');
    $this->app->instance(ModuleDiscovery::class, new ModuleDiscovery(installedJsonPath: $installedJson));

    try {
        $exitCode = Artisan::call('modules:compile');
    } finally {
        unlink($installedJson);
    }

    expect($exitCode)->toBe(0);
});

it('modules:compile reports discovered manifests from the bound ModuleDiscovery', function (): void {
    $this->app->instance(
        ModuleDiscovery::class,
        new ModuleDiscovery(fallbackModules: [StubModuleDefinition::class]),
    );

    $exitCode = Artisan::call('modules:compile');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Discovered 1 module manifest(s).');
});

it('registers and executes CompileModulesCommand under a custom modules.compile_command configuration', function (): void {
    config()->set('modules.compile_command', 'acme:modules:compile');
    $this->app->forgetInstance(ModulesOptions::class);
    $this->app->register(ModulesServiceProvider::class, force: true);
    $this->app->instance(
        ModuleDiscovery::class,
        new ModuleDiscovery(fallbackModules: [StubModuleDefinition::class]),
    );

    expect(new CompileModulesCommand()->getName())->toBe('modules:compile')
        ->and(Artisan::all())->toHaveKey('acme:modules:compile')
        ->and(Artisan::all())->not->toHaveKey('modules:compile');

    $exitCode = Artisan::call('acme:modules:compile');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Discovered 1 module manifest(s).');
});
