<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Packman\Modules\Data\ModuleManifest;
use Packman\Modules\Exceptions\InvalidManifestException;
use Packman\Modules\Internal\Definition\ModuleDependency;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Modules\Internal\Settings\DefaultSettingDefinition;
use Packman\Modules\ModulesServiceProvider;
use Packman\Modules\Tests\Stubs\StubModuleDefinition;

beforeEach(function (): void {
    $this->app->register(ModulesServiceProvider::class);
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
