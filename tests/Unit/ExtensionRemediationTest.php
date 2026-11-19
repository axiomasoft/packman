<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Data\Compiler\CompiledRegistry;
use Packman\Modules\Data\Entities\EntityDefinition;
use Packman\Modules\Data\Lifecycle\ModuleDependency;
use Packman\Modules\Data\Lifecycle\ModuleManifest;
use Packman\Modules\Exceptions\Compiler\CompilationException;
use Packman\Modules\Internal\Compiler\RegistryCompiler;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Modules\Internal\Events\ManifestListeners;
use Packman\Modules\Internal\Registry\CompiledModuleRegistry;
use Packman\Modules\ModulesServiceProvider;
use Packman\Modules\Testing\ArrayEntityRegistry;

final class RemediationManifestListener
{
    public static int $calls = 0;

    public function handle(stdClass $event): void
    {
        self::$calls++;
    }
}

it('resolves module activity once per entity-list operation without caching across contexts', function (): void {
    $calls = 0;
    $active = ['example/alpha'];
    $registry = new ArrayEntityRegistry(activeModules: function ($context) use (&$calls, &$active): array {
        $calls++;

        return $active;
    });
    foreach (range(1, 20) as $index) {
        $registry->register(new EntityDefinition(
            handle: 'example.entity'.$index, model: Model::class, table: 'example_entities',
            module: $index < 11 ? 'example/alpha' : 'example/beta', labelSingular: 'Entity', labelPlural: 'Entities',
            capabilities: [], fields: [], titleAttribute: null,
        ));
    }
    $context = resolve(ContextResolverInterface::class)->current();
    expect($registry->all($context))->toHaveCount(10)->and($calls)->toBe(1);
    $active = [];
    expect($registry->all($context))->toBeEmpty()->and($calls)->toBe(2);
    expect($registry->all())->toHaveCount(20)->and($calls)->toBe(2);
});

it('rejects a dependency cycle with its chain before compilation', function (): void {
    $a = ModuleManifest::make('example/alpha')->title('A')->tablePrefix('aaa')->requires([ModuleDependency::module('example/beta')]);
    $b = ModuleManifest::make('example/beta')->title('B')->tablePrefix('bbb')->requires([ModuleDependency::module('example/alpha')]);
    expect(fn () => (new RegistryCompiler)->compile([$a, $b], []))
        ->toThrow(CompilationException::class, 'example/alpha -> example/beta -> example/alpha');
});

it('dispatches compiled manifest listeners once only while their owning module is active', function (): void {
    RemediationManifestListener::$calls = 0;
    $manifest = ModuleManifest::make('example/listener')->title('Listener')->tablePrefix('lst')
        ->listens([stdClass::class => [RemediationManifestListener::class]]);
    $compiled = CompiledRegistry::fromArray((new RegistryCompiler)->compile([$manifest], [])->toArray());
    $active = false;
    $registry = Mockery::mock(ModuleRegistry::class);
    $registry->shouldReceive('compiled')->andReturn($compiled);
    $registry->shouldReceive('isActive')->andReturnUsing(function () use (&$active): bool {
        return $active;
    });
    $listeners = new ManifestListeners(resolve('events'), $registry, resolve(ContextResolverInterface::class));
    $listeners->register($compiled);
    $listeners->register($compiled);
    event(new stdClass);
    expect(RemediationManifestListener::$calls)->toBe(0);
    $active = true;
    event(new stdClass);
    expect(RemediationManifestListener::$calls)->toBe(1);
    $active = false;
    event(new stdClass);
    expect(RemediationManifestListener::$calls)->toBe(1);
});

it('can compile a missing production registry while ordinary console boot still fails', function (): void {
    $previousArgv = $_SERVER['argv'] ?? null;
    $this->app->detectEnvironment(static fn (): string => 'production');
    $this->app->useBootstrapPath(database_path('bootstrap'));

    try {
        $_SERVER['argv'] = ['artisan', 'modules:compile'];
        // The kernel provider already registered the module provider; re-register it under the production environment.
        $this->app->register(ModulesServiceProvider::class, true);
        $this->app->instance(ModuleDiscovery::class, new ModuleDiscovery(installedJsonPath: '/nonexistent-test-installed.json'));
        $this->artisan('modules:compile')->assertSuccessful();
        $path = base_path(CompiledModuleRegistry::CACHE_PATH);
        expect(is_file($path))->toBeTrue();
        unlink($path);
        resolve(ModuleRegistry::class)->flush();
        $_SERVER['argv'] = ['artisan', 'list'];
        expect(fn () => new ModulesServiceProvider($this->app)->boot())->toThrow(RuntimeException::class);
    } finally {
        $_SERVER['argv'] = $previousArgv;
        $this->app->detectEnvironment(static fn (): string => 'testing');
    }
});

it('exempts cold production modules:compile with leading CLI flags and optimize lifecycle commands while rejecting normal commands', function (): void {
    $previousArgv = $_SERVER['argv'] ?? null;
    $this->app->detectEnvironment(static fn (): string => 'production');
    $this->app->useBootstrapPath(database_path('bootstrap'));
    $path = base_path(CompiledModuleRegistry::CACHE_PATH);

    try {
        if (is_file($path)) {
            unlink($path);
        }

        $_SERVER['argv'] = ['artisan', '--env=production', '-q', '--no-interaction', 'modules:compile'];
        $this->app->register(ModulesServiceProvider::class, true);
        $this->app->instance(ModuleDiscovery::class, new ModuleDiscovery(installedJsonPath: '/nonexistent-test-installed.json'));

        $this->artisan('modules:compile')->assertSuccessful();
        expect(is_file($path))->toBeTrue();

        unlink($path);
        resolve(ModuleRegistry::class)->flush();

        foreach ([
            ['artisan', '--env', 'production', 'modules:compile'],
            ['artisan', 'optimize'],
            ['artisan', '-q', '--env=production', 'optimize'],
            ['artisan', 'package:discover'],
            ['artisan', 'config:cache'],
            ['artisan', 'route:cache'],
            ['artisan', 'view:cache'],
            ['artisan', 'event:cache'],
        ] as $argv) {
            $_SERVER['argv'] = $argv;
            new ModulesServiceProvider($this->app)->boot();
        }

        $_SERVER['argv'] = ['artisan', '--env=production', 'list'];
        expect(fn () => new ModulesServiceProvider($this->app)->boot())->toThrow(RuntimeException::class);
    } finally {
        if (is_file($path)) {
            @unlink($path);
        }
        $_SERVER['argv'] = $previousArgv;
        $this->app->detectEnvironment(static fn (): string => 'testing');
    }
});
