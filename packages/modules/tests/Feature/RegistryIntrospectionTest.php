<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ScopeRef;
use Packman\Modules\Contracts\Lifecycle\ModuleActivationGate;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Contracts\Registry\ModuleStateReader;
use Packman\Modules\Contracts\Registry\RegistryIntrospection;
use Packman\Modules\Data\Compiler\CompiledModule;
use Packman\Modules\Data\Entities\Entity;
use Packman\Modules\Data\Entities\FieldDefinition;
use Packman\Modules\Data\Extenders\Api;
use Packman\Modules\Data\Extenders\Permissions;
use Packman\Modules\Data\Lifecycle\ModuleManifest;
use Packman\Modules\Exceptions\Registry\InvalidRegistryPoint;
use Packman\Modules\Exceptions\Registry\RegistryInputUnavailable;
use Packman\Modules\Exceptions\Registry\StaleRegistryRevision;
use Packman\Modules\Internal\Compiler\RegistryCompiler;
use Packman\Modules\Internal\Extend\Menu;
use Packman\Modules\Internal\Gate\ModuleActivationDecision;
use Packman\Modules\Internal\Registry\ArrayModuleStateReader;
use Packman\Modules\Internal\Registry\CompiledModuleRegistry;
use Packman\Modules\Internal\Registry\ReadRegistryIntrospection;
use Packman\Modules\Internal\Registry\RepositoryModuleRegistryCache;
use Packman\Modules\Internal\Support\ModulesOptions;
use Packman\Modules\ModulesServiceProvider;
use Packman\Modules\Tests\Stubs\Widget;

beforeEach(function (): void {
    $this->app->register(ModulesServiceProvider::class);
    $this->cachePath = sys_get_temp_dir().'/packman_intro_test_'.bin2hex(random_bytes(4)).'.php';
});

afterEach(function (): void {
    if (isset($this->cachePath) && is_file($this->cachePath)) {
        unlink($this->cachePath);
    }
});

function introTenant(string $accountId = 'acct-1'): ContextScopeInterface
{
    return new ScopeRef(
        id: $accountId,
        attributes: [
            'slug' => $accountId,
            'status' => 'active',
        ],
    );
}

function introRegistry(
    string $path,
    ModuleStateReader $reader,
    array $enabled = [],
): CompiledModuleRegistry {
    return new CompiledModuleRegistry(
        cachePath: $path,
        stateReader: $reader,
        cache: new RepositoryModuleRegistryCache(
            Cache::store(),
            ModulesOptions::fromArray(['cache' => ['ttl' => 3600, 'prefix' => 'modules']]),
        ),
        versionResolver: static fn (ContextScopeInterface $ctx): int => 0,
        failIfMissing: false,
    );
}

function introIntrospection(
    ModuleRegistry $registry,
    string $path,
    ?ModuleActivationGate $gate = null,
    bool $failIfMissing = false,
): ReadRegistryIntrospection {
    return new ReadRegistryIntrospection(
        registry: $registry,
        activationGate: $gate ?? resolve(ModuleActivationGate::class),
        cachePath: $path,
        failIfMissing: $failIfMissing,
    );
}

function introCompileFixture(string $path): CompiledModuleRegistry
{
    $manifest = ModuleManifest::make('example/demo')
        ->title('Demo')
        ->tablePrefix('dem')
        ->extends([
            Entity::make(Widget::class)
                ->handle('dem.widget')
                ->table('dem_widgets')
                ->label('Widget', 'Widgets')
                ->auditable()
                ->fields([new FieldDefinition('name', 'string')]),
            Permissions::group('widget', ['view'], 'app'),
            Menu::item('app', 'dem.widgets')->label('Widgets'),
            Api::entity('dem.widget'),
        ]);

    $compiled = resolve(RegistryCompiler::class)->compile([$manifest], installedPackages: ['example/demo']);
    $module = CompiledModule::fromManifest($manifest);
    CompiledModuleRegistry::writeAtomic($path, [$module], $compiled);

    return introRegistry($path, new ArrayModuleStateReader([
        'acct-1' => ['example/demo' => ['state' => 'enabled', 'edition' => 'default']],
    ]));
}

it('projects the eight extenders and ownership from compiled bytes (INTRO-01)', function (): void {
    $registry = introCompileFixture($this->cachePath);
    $map = introIntrospection($registry, $this->cachePath)->extensionPoints();

    $extenderNames = array_values(array_filter(
        $map->extensionPoints,
        static fn (array $point): bool => ($point['kind'] ?? null) === 'extender',
    ));
    $names = array_column($extenderNames, 'name');

    expect($map->schemaVersion)->toBe(ReadRegistryIntrospection::MAP_SCHEMA)
        ->and($names)->toBe([
            'entity',
            'entity_field',
            'permissions',
            'api',
            'filament',
            'menu',
            'routes',
            'workflow',
        ])
        ->and(collect($map->extensionPoints)->contains(
            static fn (array $point): bool => ($point['kind'] ?? null) === 'entity' && ($point['handle'] ?? null) === 'dem.widget',
        ))->toBeTrue()
        ->and($map->revision)->toBe(ReadRegistryIntrospection::revisionFor($registry->compiled()))
        ->and($map->revision)->toBe($registry->compiled()->fingerprint);
});

it('returns the compiled authority fingerprint and ignores activation epoch', function (): void {
    $registry = introCompileFixture($this->cachePath);
    $fingerprint = $registry->compiled()->fingerprint;
    $enabled = $registry->isActive('example/demo', introTenant());

    expect(ReadRegistryIntrospection::revisionFor($registry->compiled()))->toBe($fingerprint)
        ->and($enabled)->toBeTrue()
        ->and($registry->compiled()->fingerprint)->toBe($fingerprint);
});

it('explains unknown modules and tenant activation without guessing (INTRO-02)', function (): void {
    $registry = introCompileFixture($this->cachePath);
    $introspection = introIntrospection($registry, $this->cachePath);

    $unknown = $introspection->why('example/missing', introTenant());
    $staticOnly = $introspection->why('example/demo', null);
    $active = $introspection->why('example/demo', introTenant());

    expect($unknown->status)->toBe('unknown')
        ->and($unknown->reasons)->toBe(['module_unknown'])
        ->and($staticOnly->hasTenantVerdict)->toBeFalse()
        ->and($staticOnly->status)->toBe('declared')
        ->and($active->hasTenantVerdict)->toBeTrue()
        ->and($active->status)->toBe('active')
        ->and($active->reasons)->toBe(['enabled_for_tenant']);
});

it('renders deterministic CLI output and requires account for tenant verdict (INTRO-03)', function (): void {
    introCompileFixture($this->cachePath);
    app()->instance(ModuleRegistry::class, introRegistry(
        $this->cachePath,
        new ArrayModuleStateReader([
            'acct-1' => ['example/demo' => ['state' => 'enabled', 'edition' => 'default']],
        ]),
    ));

    Artisan::call('modules:why', ['module' => 'example/demo']);
    $text = Artisan::output();

    Artisan::call('modules:why', ['module' => 'example/demo', '--json' => true]);
    $json = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    Artisan::call('modules:why', ['module' => 'example/demo', '--account' => 'acct-1']);
    $withAccount = Artisan::output();

    expect($text)->toContain('Tenant verdict: no')
        ->and($text)->toContain('declaration-only')
        ->and($json['module'])->toBe('example/demo')
        ->and($json['hasTenantVerdict'])->toBeFalse()
        ->and($withAccount)->toContain('Tenant verdict: yes');
});

it('redacts internal class references and sensitive topology (INTRO-04)', function (): void {
    $registry = introCompileFixture($this->cachePath);
    $encoded = json_encode(introIntrospection($registry, $this->cachePath)->extensionPoints()->extensionPoints, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain(Widget::class)
        ->and($encoded)->not->toContain('database/migrations')
        ->and($encoded)->not->toContain('connection');
});

it('reports declared constraints with compatibility passport pending (INTRO-05)', function (): void {
    $registry = introCompileFixture($this->cachePath);
    $explanation = introIntrospection($registry, $this->cachePath)->why('example/demo', null);

    expect($explanation->declaredConstraints)->toHaveKey('requires')
        ->and($explanation->declaredConstraints)->toHaveKey('editions')
        ->and($explanation->declaredConstraints['compatibilityPassport'])->toBe('pending');
});

it('does not mutate module state while introspecting (INTRO-06)', function (): void {
    $registry = introCompileFixture($this->cachePath);
    $before = filemtime($this->cachePath);
    $introspection = introIntrospection($registry, $this->cachePath);

    $introspection->extensionPoints();
    $introspection->why('example/demo', introTenant());

    expect(filemtime($this->cachePath))->toBe($before)
        ->and(file_get_contents($this->cachePath))->toBe(file_get_contents($this->cachePath));
});

it('separates missing input, unknown extenders and stale revisions (INTRO-07)', function (): void {
    $missingPath = sys_get_temp_dir().'/packman_intro_missing_'.bin2hex(random_bytes(4)).'.php';
    @unlink($missingPath);
    $registry = introRegistry($missingPath, new ArrayModuleStateReader);
    $introspection = introIntrospection($registry, $missingPath, failIfMissing: true);

    expect(fn () => $introspection->extensionPoints())
        ->toThrow(RegistryInputUnavailable::class);

    $registry = introCompileFixture($this->cachePath);
    $map = introIntrospection($registry, $this->cachePath)->extensionPoints();

    expect(fn () => ReadRegistryIntrospection::assertExtensionPoint('tools'))
        ->toThrow(InvalidRegistryPoint::class)
        ->and(fn () => ReadRegistryIntrospection::assertRevision($map, 'stale-revision'))
        ->toThrow(StaleRegistryRevision::class);

    $emptyPath = sys_get_temp_dir().'/packman_intro_empty_'.bin2hex(random_bytes(4)).'.php';
    CompiledModuleRegistry::writeAtomic($emptyPath, [CompiledModule::fromManifest(
        ModuleManifest::make('example/empty')->title('Empty')->tablePrefix('emp'),
    )]);
    $emptyMap = introIntrospection(
        introRegistry($emptyPath, new ArrayModuleStateReader),
        $emptyPath,
    )->extensionPoints();

    expect($emptyMap->extensionPoints)->not->toBeEmpty()
        ->and(collect($emptyMap->extensionPoints)
            ->filter(static fn (array $point): bool => ($point['kind'] ?? null) === 'extender')
            ->sum(static fn (array $point): int => (int) ($point['entryCount'] ?? 0)))->toBe(0);

    if (is_file($emptyPath)) {
        unlink($emptyPath);
    }
});

it('reports activation gate denial with tenant verdict (INTRO-02)', function (): void {
    $registry = introCompileFixture($this->cachePath);
    $gate = Mockery::mock(ModuleActivationGate::class);
    $gate->shouldReceive('check')->once()->andReturn(ModuleActivationDecision::deny('billing'));
    $explanation = introIntrospection($registry, $this->cachePath, $gate)->why('example/demo', introTenant());

    expect($explanation->status)->toBe('inactive')
        ->and($explanation->reasons)->toContain('activation_gate_denied')
        ->and($explanation->hasTenantVerdict)->toBeTrue();
});

it('resolves RegistryIntrospection from the container', function (): void {
    introCompileFixture($this->cachePath);
    app()->instance(ModuleRegistry::class, introRegistry(
        $this->cachePath,
        new ArrayModuleStateReader([
            'acct-1' => ['example/demo' => ['state' => 'enabled', 'edition' => 'default']],
        ]),
    ));

    expect(resolve(RegistryIntrospection::class))->toBeInstanceOf(ReadRegistryIntrospection::class);
});
