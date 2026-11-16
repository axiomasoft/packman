<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Packman\Data\Laravel\JsonDocuments;
use Packman\Features\Data\FeatureFlagDefinition;
use Packman\Modules\Data\Compiler\CompiledRegistry;
use Packman\Modules\Data\Entities\Entity;
use Packman\Modules\Data\Entities\FieldDefinition;
use Packman\Modules\Data\Extenders\Permissions;
use Packman\Modules\Data\Lifecycle\ModuleManifest;
use Packman\Modules\Exceptions\Compiler\CompilationException;
use Packman\Modules\Internal\Compiler\RegistryCompiler;
use Packman\Modules\Internal\Extend\Menu;

function determinismEntity(string $name, string $prefix, string $handle): Entity
{
    return Entity::make(Model::class)
        ->handle($handle)
        ->table($prefix.'_things')
        ->fields([new FieldDefinition('name', 'string')]);
}

function determinismManifest(string $name, string $prefix, string $handle, array $extenders = []): ModuleManifest
{
    return ModuleManifest::make($name)
        ->title($name)
        ->tablePrefix($prefix)
        ->extends([
            determinismEntity($name, $prefix, $handle),
            ...$extenders,
        ]);
}

it('publishes one fingerprint for shuffled module, declaration and associative order', function (): void {
    $alpha = determinismManifest('example/alpha', 'aaa', 'aaa.thing', [
        Permissions::group('thing', ['view', 'create'], 'app'),
        Menu::item('app', 'home')->label('Home'),
        Menu::item('app', 'things')->label('Things')->after('home'),
    ]);
    $beta = determinismManifest('example/beta', 'bbb', 'bbb.thing', [
        Permissions::group('widget', ['view'], 'app'),
    ]);

    $first = resolve(RegistryCompiler::class)->compile([$alpha, $beta], []);
    $second = resolve(RegistryCompiler::class)->compile([$beta, $alpha], []);

    expect($second->fingerprint)->toBe($first->fingerprint)
        ->and($second->toArray()['extensions'])->toBe($first->toArray()['extensions'])
        ->and($second->toArray()['modules'])->toBe($first->toArray()['modules'])
        ->and(json_encode($second->toArray()['extensions']))->toBe(json_encode($first->toArray()['extensions']));
});

it('keeps semantic menu order while hashing key-sorted payloads', function (): void {
    $manifest = determinismManifest('example/alpha', 'aaa', 'aaa.thing', [
        Menu::item('app', 'home')->label('Home'),
        Menu::item('app', 'things')->label('Things')->after('home'),
    ]);

    $compiled = resolve(RegistryCompiler::class)->compile([$manifest], []);
    $menuIds = array_column(array_filter(
        $compiled->extensions,
        static fn (array $row): bool => $row['point'] === 'menu',
    ), 'id');

    expect($menuIds)->toBe(['app:home', 'app:things'])
        ->and($compiled->menu[0]->handle)->toBe('home')
        ->and($compiled->menu[1]->handle)->toBe('things');
});

it('rejects every replacement error with distinct diagnostics', function (
    callable $manifests,
    string $needle,
): void {
    expect(fn () => resolve(RegistryCompiler::class)->compile($manifests(), []))
        ->toThrow(CompilationException::class, $needle);
})->with([
    'missing target' => [
        fn (): array => [
            determinismManifest('example/alpha', 'aaa', 'aaa.thing')
                ->replace('entity', 'missing.thing', 'aaa.thing'),
        ],
        'missing target [missing.thing]',
    ],
    'missing replacement' => [
        fn (): array => [
            determinismManifest('example/alpha', 'aaa', 'aaa.thing')
                ->replace('entity', 'aaa.thing', 'ghost.thing'),
        ],
        'missing replacement [ghost.thing]',
    ],
    'foreign replacement' => [
        fn (): array => [
            determinismManifest('example/alpha', 'aaa', 'aaa.thing'),
            determinismManifest('example/beta', 'bbb', 'bbb.thing')
                ->replace('entity', 'aaa.thing', 'aaa.thing'),
        ],
        'must own replacement [aaa.thing]',
    ],
    'duplicate incoming' => [
        fn (): array => [
            determinismManifest('example/alpha', 'aaa', 'aaa.thing'),
            determinismManifest('example/beta', 'bbb', 'bbb.thing')
                ->replace('entity', 'aaa.thing', 'bbb.thing'),
            determinismManifest('example/gamma', 'ggg', 'ggg.thing')
                ->replace('entity', 'aaa.thing', 'ggg.thing'),
        ],
        'already has an incoming edge',
    ],
    'cycle' => [
        fn (): array => [
            determinismManifest('example/alpha', 'aaa', 'aaa.thing')
                ->replace('entity', 'bbb.thing', 'aaa.thing'),
            determinismManifest('example/beta', 'bbb', 'bbb.thing')
                ->replace('entity', 'aaa.thing', 'bbb.thing'),
        ],
        'Replacement graph on [entity] is cyclic',
    ],
]);

it('removes a replaced target from the effective slice and keeps it in provenance', function (): void {
    $alpha = determinismManifest('example/alpha', 'aaa', 'aaa.thing');
    $beta = determinismManifest('example/beta', 'bbb', 'bbb.thing')
        ->replace('entity', 'aaa.thing', 'bbb.thing');

    $compiled = resolve(RegistryCompiler::class)->compile([$alpha, $beta], []);

    expect($compiled->entities)->toHaveKey('bbb.thing')
        ->and($compiled->entities)->not->toHaveKey('aaa.thing')
        ->and($compiled->replacements)->toBe([
            ['point' => 'entity', 'target' => 'aaa.thing', 'replacement' => 'bbb.thing', 'owner' => 'example/beta'],
        ]);
});

it('rejects a menu missing afterHandle target and a menu cycle', function (array $extenders, string $needle): void {
    $manifest = determinismManifest('example/alpha', 'aaa', 'aaa.thing', $extenders);

    expect(fn () => resolve(RegistryCompiler::class)->compile([$manifest], []))
        ->toThrow(CompilationException::class, $needle);
})->with([
    'missing after' => [
        [Menu::item('app', 'things')->after('dashboard')],
        'missing afterHandle [dashboard]',
    ],
    'cycle' => [
        [
            Menu::item('app', 'home')->after('things'),
            Menu::item('app', 'things')->after('home'),
        ],
        'Menu afterHandle graph is cyclic',
    ],
]);

it('rejects unknown schema, missing fingerprint and hash mismatch', function (array $mutate, string $needle): void {
    $compiled = resolve(RegistryCompiler::class)->compile([
        determinismManifest('example/alpha', 'aaa', 'aaa.thing'),
    ], []);
    $array = $compiled->toArray();

    foreach ($mutate as $key => $value) {
        if ($value === '__unset') {
            unset($array[$key]);
        } else {
            $array[$key] = $value;
        }
    }

    expect(fn () => CompiledRegistry::fromArray($array))
        ->toThrow(CompilationException::class, $needle);
})->with([
    'old artifact' => [['schema' => '__unset'], 'missing schema'],
    'unknown schema' => [['schema' => 'packman-registry/0'], 'unknown schema'],
    'missing fingerprint' => [['fingerprint' => '__unset'], 'missing fingerprint'],
    'mismatch' => [['fingerprint' => str_repeat('a', 64)], 'fingerprint mismatch'],
]);

it('does not let a hydrated mutation change authority bytes or fingerprint', function (): void {
    $manifest = determinismManifest('example/alpha', 'aaa', 'aaa.thing', [
        Menu::item('app', 'home')->label('Home'),
    ]);
    $compiled = resolve(RegistryCompiler::class)->compile([$manifest], []);
    $before = $compiled->toArray();
    $fingerprint = $compiled->fingerprint;

    $items = $compiled->menu;
    $hydrated = $items[0];
    $hydrated->handle = 'mutated';
    $hydrated->label = 'Mutated';

    expect($compiled->fingerprint)->toBe($fingerprint)
        ->and($compiled->toArray())->toBe($before)
        ->and($compiled->toArray()['menu'][0]['handle'])->toBe('home');

    $again = resolve(RegistryCompiler::class)->compile([$manifest], []);

    expect($again->fingerprint)->toBe($fingerprint)
        ->and($again->menu[0]->handle)->toBe('home');
});

it('round trips feature flag payloads without restricting valid JSON to maps', function (): void {
    foreach ([false, 42, 'scalar', ['one', 'two'], ['nested' => ['one', 'two']]] as $value) {
        $flag = new FeatureFlagDefinition(
            key: 'payload', payload: JsonDocuments::capture($value),
        );
        $compiled = new CompiledRegistry([], [], [], [], [], [], [], [], featureFlags: [$flag]);
        $array = $compiled->toArray();
        $copy = CompiledRegistry::fromArray($array);
        expect($copy->toArray())->toBe($array)
            ->and(JsonDocuments::decode($copy->featureFlags[0]->payload))->toBe($value);
    }
});

it('rejects malformed serialized slices before hydration', function (): void {
    foreach ([['entityFields', [['extender' => [], 'declaringModule' => 42, 'sleeping' => false]]],
        ['settings', [['namespace' => 'example', 'definition' => 'not a map']]],
        ['featureFlags', [['key' => 'example', 'default' => 'not a bool', 'module' => null, 'payload' => null]]],
        ['permissions', ['not a map']]] as [$key, $invalid]) {
        $payload = CompiledRegistry::empty()->toArray();
        $payload[$key] = $invalid;
        expect(fn () => CompiledRegistry::fromArray($payload))->toThrow(CompilationException::class);
    }
});

it('round trips interface event class strings in compiled listeners', function (): void {
    $listeners = ['example/events' => [Stringable::class => [stdClass::class]]];
    $compiled = new CompiledRegistry([], [], [], [], [], [], [], [], listeners: $listeners);
    expect(CompiledRegistry::fromArray($compiled->toArray())->listeners)->toBe($listeners);
});
