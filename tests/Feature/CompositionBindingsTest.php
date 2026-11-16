<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Modules\Contracts\EntityRegistry;
use Packman\Modules\Contracts\ModuleActivationGate;
use Packman\Modules\Contracts\ModuleLifecycle;
use Packman\Modules\Contracts\ModuleRegistry;
use Packman\Modules\Contracts\ModuleRegistryCache;
use Packman\Modules\Contracts\ModuleStateReader;
use Packman\Modules\Contracts\RecordsRegistrar;
use Packman\Modules\Contracts\RegistryIntrospection;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Modules\Internal\Gate\AllowAllActivationGate;
use Packman\Modules\Internal\InMemoryEntityRegistry;
use Packman\Modules\Internal\Lifecycle\DatabaseModuleLifecycle;
use Packman\Modules\Internal\Lifecycle\DatabaseModuleStateReader;
use Packman\Modules\Internal\Lifecycle\DatabaseRecordsRegistrar;
use Packman\Modules\Internal\ReadRegistryIntrospection;
use Packman\Modules\Internal\Registry\CompiledModuleRegistry;
use Packman\Modules\Internal\Registry\RepositoryModuleRegistryCache;
use Packman\Modules\Internal\Settings\CompiledSettingDefaultsProvider;
use Packman\Modules\Internal\Support\ModulesOptions;
use Packman\Modules\Laravel\LifecycleRegistrar;
use Packman\Modules\Laravel\ModulesBootRegistrar;
use Packman\Modules\Laravel\RegistryRegistrar;
use Packman\Modules\Laravel\SettingsRegistrar;
use Packman\Modules\ModulesServiceProvider;
use Packman\Settings\Contracts\SettingDefaultsProviderInterface;

beforeEach(function (): void {
    $this->app->register(ModulesServiceProvider::class);
});

it('keeps Registry register bindings on the measured key, concrete and lifetime', function (): void {
    $app = $this->app;
    assertSingletonBinding($app, InMemoryEntityRegistry::class, InMemoryEntityRegistry::class);
    assertSingletonBinding($app, EntityRegistry::class, InMemoryEntityRegistry::class);
    assertSingletonBinding($app, ModuleDiscovery::class, ModuleDiscovery::class);
    assertSingletonBinding($app, ModuleActivationGate::class, AllowAllActivationGate::class);
    assertSingletonBinding($app, ModuleRegistryCache::class, RepositoryModuleRegistryCache::class);
    assertSingletonBinding($app, ModuleRegistry::class, CompiledModuleRegistry::class);
    assertSingletonBinding($app, RegistryIntrospection::class, ReadRegistryIntrospection::class);
});

it('keeps Lifecycle register bindings on the measured key, concrete and lifetime', function (): void {
    $app = $this->app;
    assertSingletonBinding($app, ModuleStateReader::class, DatabaseModuleStateReader::class);
    assertSingletonBinding($app, RecordsRegistrar::class, DatabaseRecordsRegistrar::class);
    assertSingletonBinding($app, ModuleLifecycle::class, DatabaseModuleLifecycle::class);

    expect($app->getBindings())->toHaveKey('modules.wiring');
    expect($app->isShared('modules.wiring'))->toBeTrue();
    $first = $app->make('modules.wiring');
    expect($first)->toBeArray()
        ->and($first)->toHaveKeys(['codeVersions', 'migrationPaths']);
    expect($app->make('modules.wiring'))->toBe($first);
    $app->forgetScopedInstances();
    expect($app->make('modules.wiring'))->toBe($first);
});

it('keeps Settings register bindings on the measured key, concrete and lifetime', function (): void {
    assertSingletonBinding(
        $this->app,
        SettingDefaultsProviderInterface::class,
        CompiledSettingDefaultsProvider::class,
    );
});

it('resolves ModulesOptions from the composition instance', function (): void {
    $app = $this->app;
    expect($app->bound(ModulesOptions::class))->toBeTrue();
    $first = $app->make(ModulesOptions::class);
    expect($first)->toBeInstanceOf(ModulesOptions::class)
        ->and($app->make(ModulesOptions::class))->toBe($first);
});

it('reads module options from the nested kernel configuration', function (): void {
    config()->set('modules.connection', 'module-test');
    app()->forgetInstance(ModulesOptions::class);
    config()->set('modules.cache', ['store' => 'array', 'ttl' => 42, 'prefix' => 'module-test']);
    app()->forgetInstance(ModulesOptions::class);
    $this->app->register(ModulesServiceProvider::class, force: true);

    $options = $this->app->make(ModulesOptions::class);
    expect($options->connection)->toBe('module-test')
        ->and($options->cacheStore)->toBe('array')
        ->and($options->cacheTtl)->toBe(42)
        ->and($options->cachePrefix)->toBe('module-test');
});

it('registers composition modules through PackageRegistrar', function (): void {
    $registrars = [
        new RegistryRegistrar,
        new LifecycleRegistrar,
        new SettingsRegistrar,
        new ModulesBootRegistrar,
    ];

    foreach ($registrars as $registrar) {
        expect($registrar)->toBeInstanceOf(PackageRegistrar::class);
        $registrar->boot($this->app);
    }
});

function assertSingletonBinding(
    Application $app,
    string $abstract,
    string $expectedConcrete,
): void {
    expect($app->getBindings())->toHaveKey($abstract);
    expect($app->isShared($abstract))->toBeTrue();
    $first = $app->make($abstract);
    $second = $app->make($abstract);
    expect($first)->toBeInstanceOf($expectedConcrete);
    expect($first)->toBe($second);
    $app->forgetScopedInstances();
    expect($app->make($abstract))->toBe($first);
}

it('reads module code versions from installed.json and fails closed on a corrupt manifest', function (): void {
    $root = sys_get_temp_dir().'/packman-wiring-'.bin2hex(random_bytes(4));
    mkdir($root.'/vendor/composer', recursive: true);
    $manifest = $root.'/vendor/composer/installed.json';
    $original = $this->app->basePath();
    $this->app->setBasePath($root);

    try {
        file_put_contents($manifest, json_encode(['packages' => [['name' => 'acme/mod', 'type' => 'packman-module', 'version' => '1.2.3']]]));
        expect($this->app->make('modules.wiring')['codeVersions'])->toBe(['acme/mod' => '1.2.3']);

        $this->app->forgetInstance('modules.wiring');
        file_put_contents($manifest, '{corrupt');
        expect(fn () => $this->app->make('modules.wiring'))->toThrow(JsonException::class);
    } finally {
        $this->app->setBasePath($original);
        $this->app->forgetInstance('modules.wiring');
        unlink($manifest);
        rmdir($root.'/vendor/composer');
        rmdir($root.'/vendor');
        rmdir($root);
    }
});
