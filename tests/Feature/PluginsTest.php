<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Data\DoctorContext;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Persistence\RegistersInDatabase;
use Packman\Extensions\Concerns\Runtime\DeclaresFeatureFlags;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithDatabaseRegistration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Extensions\Contracts\Runtime\WithFeatureFlags;
use Packman\Features\Data\FeatureFlagDefinition;
use Packman\Plugins\Concerns\RegistersNothing;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Contributions\Extensions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Enums\Plugin\PluginSource;
use Packman\Plugins\Enums\PluginErrorCode;
use Packman\Plugins\Exceptions\Graph\InvalidExtensionGraphException;
use Packman\Plugins\Exceptions\Graph\MissingExtensionException;
use Packman\Plugins\Exceptions\Registry\RegistryFrozenException;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;
use Packman\Plugins\Internal\Contributions\FrozenContributions;
use Packman\Plugins\Internal\Graph\PluginEntry;
use Packman\Plugins\Internal\Graph\PluginGraphCompiler;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginRegistrar;
use Packman\Plugins\Testing\PluginGraph;
use Packman\Plugins\Tests\Fixtures\BadKeyGreeter;
use Packman\Plugins\Tests\Fixtures\Greeter;
use Packman\Plugins\Tests\Fixtures\HelloGreeter;
use Packman\Plugins\Tests\Fixtures\HolaGreeter;
use Packman\Plugins\Tests\Fixtures\NotAGreeter;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Testkit\Concerns\Assertions\AssertsRegisteredErrors;

require_once __DIR__.'/../Fixtures/TestContracts.php';

uses(AssertsRegisteredErrors::class);

abstract class PlgBase implements ExtensionPlugin
{
    /** @return array{0: string, 1?: list<string>, 2?: list<string>} */
    abstract protected function spec(): array;

    public function manifest(): PluginManifest
    {
        [$id, $requires, $optional] = $this->spec() + [1 => [], 2 => []];

        return new PluginManifest($id, '1.0.0', $requires, $optional);
    }

    public function register(PluginRegistrar $registrar): void {}
}

final class PlgCore extends PlgBase
{
    protected function spec(): array
    {
        return ['test.core'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind('core_hook', Greeter::class, 'test.core'));
    }
}

final class PlgOwner extends PlgBase
{
    protected function spec(): array
    {
        return ['test.owner'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind('greeter', Greeter::class, 'test.owner'));
        $registrar->contribute('greeter', HelloGreeter::class);
    }
}

final class PlgExtension extends PlgBase
{
    protected function spec(): array
    {
        return ['test.extension', ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', new HolaGreeter);
    }
}

final class PlgOptional extends PlgBase
{
    protected function spec(): array
    {
        return ['test.optional', [], ['test.absent', 'test.owner']];
    }
}

final class PlgCycleA extends PlgBase
{
    protected function spec(): array
    {
        return ['test.cycle-a', ['test.cycle-b']];
    }
}

final class PlgCycleB extends PlgBase
{
    protected function spec(): array
    {
        return ['test.cycle-b', ['test.cycle-a']];
    }
}

final class PlgMissingRequirement extends PlgBase
{
    protected function spec(): array
    {
        return ['test.needy', ['test.absent']];
    }
}

final class PlgDuplicateKey extends PlgBase
{
    protected function spec(): array
    {
        return ['test.dup-key', ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', HelloGreeter::class);
    }
}

final class PlgDuplicateKind extends PlgBase
{
    protected function spec(): array
    {
        return ['test.dup-kind', ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind('greeter', Greeter::class, 'test.dup-kind'));
    }
}

final class PlgUnknownKind extends PlgBase
{
    protected function spec(): array
    {
        return ['test.unknown-kind'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('nonexistent', HelloGreeter::class);
    }
}

final class PlgWrongInterface extends PlgBase
{
    protected function spec(): array
    {
        return ['test.wrong-interface', ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', NotAGreeter::class);
    }
}

final class PlgBadKey extends PlgBase
{
    protected function spec(): array
    {
        return ['test.bad-key', ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', BadKeyGreeter::class);
    }
}

final class PlgNotRequired extends PlgBase
{
    protected function spec(): array
    {
        return ['test.not-required'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', HelloGreeter::class);
    }
}

final class PlgForeignOwner extends PlgBase
{
    protected function spec(): array
    {
        return ['test.foreign'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind('stolen', Greeter::class, 'test.owner'));
    }
}

final class PlgBadManifest implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('NoVendor', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void {}
}

final class PlgLate extends PlgBase
{
    public static ?PluginRegistrar $registrar = null;

    protected function spec(): array
    {
        return ['test.late'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        self::$registrar = $registrar;
    }
}

final class PlgOptionalExtension extends PlgBase
{
    protected function spec(): array
    {
        return ['test.optional-extension', [], ['test.owner']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute('greeter', HolaGreeter::class);
    }
}

/** @param class-string ...$plugins */
function compilePlugins(string ...$plugins): Contributions&PluginInspector
{
    $entries = [PluginEntry::builtIn(PlgCore::class)];

    foreach (array_values($plugins) as $index => $plugin) {
        $entries[] = PluginEntry::config($plugin, $index);
    }

    return (new PluginGraphCompiler(app(), 'test.core'))->compile($entries);
}

/** @param class-string ...$plugins */
function compileError(string ...$plugins): string
{
    try {
        compilePlugins(...$plugins);
    } catch (InvalidConfiguration $e) {
        return implode(' | ', $e->violations);
    }

    return 'compiled';
}

it('validates manifests', function (): void {
    expect(static fn () => new PluginManifest('acme', '1.0.0'))->toThrow(InvalidConfiguration::class, 'not a plugin id')
        ->and(static fn () => new PluginManifest('Acme.Shop', '1.0.0'))->toThrow(InvalidConfiguration::class)
        ->and(static fn () => new PluginManifest('acme.shop', '1.0'))->toThrow(InvalidConfiguration::class, 'semver')
        ->and(static fn () => new PluginManifest('acme.shop', '1.0.0', ['acme.shop']))->toThrow(InvalidConfiguration::class, 'lists itself')
        ->and(static fn () => new PluginManifest('acme.shop', '1.0.0', ['a.b', 'a.b']))->toThrow(InvalidConfiguration::class, 'twice')
        ->and(static fn () => new PluginManifest('acme.shop', '1.0.0', ['a.b'], ['a.b']))->toThrow(InvalidConfiguration::class, 'both')
        ->and((new PluginManifest('acme.shop-v2', '1.2.3-rc.1+build.5', ['a.b'], ['c.d']))->id)->toBe('acme.shop-v2');
});

it('validates contribution kinds', function (): void {
    expect(static fn () => new ContributionKind('Greeter', Greeter::class, 'a.b'))->toThrow(InvalidConfiguration::class, 'snake_case')
        ->and(static fn () => new ContributionKind('greeter', 'Nope\\Missing', 'a.b'))->toThrow(InvalidConfiguration::class, 'does not exist')
        ->and(static fn () => new ContributionKind('greeter', Greeter::class, 'owner'))->toThrow(InvalidConfiguration::class, 'plugin id');
});

it('compiles core plugin alone and via PluginGraph helper', function (): void {
    $registry = PluginGraph::compile(app(), [], 'test.core', [PlgCore::class]);

    expect($registry->keys('core_hook'))->toBe([])
        ->and(array_map(static fn ($p): string => $p->id, $registry->describe()))->toBe(['test.core'])
        ->and($registry->describe()[0]->kinds[0]->name)->toBe('core_hook');
});

it('merges contributions from required plugins in load order', function (): void {
    $registry = compilePlugins(PlgExtension::class, PlgOwner::class);

    expect(array_map(static fn ($p): string => $p->id, $registry->describe()))->toBe(['test.core', 'test.owner', 'test.extension'])
        ->and($registry->keys('greeter'))->toBe(['test.hello', 'test.hola'])
        ->and($registry->has('greeter', 'test.hola'))->toBeTrue()
        ->and($registry->get('greeter', 'test.hola', Greeter::class)->greet())->toBe('hola')
        ->and($registry->get('greeter', 'test.hello', Greeter::class))->toBe($registry->get('greeter', 'test.hello', Greeter::class));

    $description = $registry->describe()[2];
    expect([$description->version, $description->source, $description->requires])->toBe(['1.0.0', PluginSource::Config, ['test.owner']])
        ->and($description->contributions[0]->class)->toBe(HolaGreeter::class);
});

it('orders independent plugins by id whatever the registration order', function (): void {
    $forward = array_map(static fn ($p): string => $p->id, compilePlugins(PlgOwner::class, PlgOptional::class, PlgExtension::class)->describe());
    $reverse = array_map(static fn ($p): string => $p->id, compilePlugins(PlgExtension::class, PlgOptional::class, PlgOwner::class)->describe());

    expect($forward)->toBe($reverse)->toBe(['test.core', 'test.owner', 'test.extension', 'test.optional']);
});

it('ignores an optional plugin that is absent and loads a present one first', function (): void {
    expect(compileError(PlgOptional::class))->toBe('compiled')
        ->and(array_map(static fn ($p): string => $p->id, compilePlugins(PlgOptional::class, PlgOwner::class)->describe()))->toBe(['test.core', 'test.owner', 'test.optional']);
});

it('reports graph problems with the plugin and where it was registered', function (string $problem, array $plugins, string $expected): void {
    expect(compileError(...$plugins))->toContain($expected);
})->with([
    'cycle' => ['cycle', [PlgCycleA::class, PlgCycleB::class], "packman.plugins.0: plugin 'test.cycle-a' (PlgCycleA): dependency cycle among test.cycle-a, test.cycle-b"],
    'missing requirement' => ['missing', [PlgOptional::class, PlgMissingRequirement::class], "packman.plugins.1: plugin 'test.needy' (PlgMissingRequirement): requires 'test.absent', which is not registered"],
    'duplicate id' => ['dup id', [PlgOwner::class, PlgExtension::class, PlgOwner::class], "packman.plugins.2: plugin id 'test.owner' is already registered by packman.plugins.0"],
    'duplicate key' => ['dup key', [PlgOwner::class, PlgDuplicateKey::class], "packman.plugins.1: plugin 'test.dup-key' (PlgDuplicateKey): greeter/test.hello is already contributed by 'test.owner'"],
    'duplicate kind' => ['dup kind', [PlgOwner::class, PlgDuplicateKind::class], "packman.plugins.1: plugin 'test.dup-kind' (PlgDuplicateKind): kind 'greeter' is already declared by 'test.owner'"],
    'unknown kind' => ['unknown kind', [PlgUnknownKind::class], "packman.plugins.0: plugin 'test.unknown-kind' (PlgUnknownKind): contributes to unknown kind 'nonexistent'"],
    'wrong interface' => ['interface', [PlgOwner::class, PlgWrongInterface::class], "packman.plugins.1: plugin 'test.wrong-interface' (PlgWrongInterface): greeter contribution ".NotAGreeter::class.' must implement '.Greeter::class],
    'bad key' => ['key', [PlgOwner::class, PlgBadKey::class], "packman.plugins.1: plugin 'test.bad-key' (PlgBadKey): greeter contribution ".BadKeyGreeter::class.' must have a key() returning a lowercase dotted key'],
    'kind of a plugin it does not require' => ['not required', [PlgOwner::class, PlgNotRequired::class], "packman.plugins.1: plugin 'test.not-required' (PlgNotRequired): kind 'greeter' belongs to 'test.owner', which this plugin does not require"],
    'kind declared for another owner' => ['foreign', [PlgForeignOwner::class], "PlgForeignOwner: Invalid configuration (1): plugin.kind.owner: plugin 'test.foreign' declared kind 'stolen' for 'test.owner'"],
    'bad manifest' => ['manifest', [PlgBadManifest::class], "PlgBadManifest: Invalid configuration (1): plugin.manifest.id: 'NoVendor' is not a plugin id"],
    'not a plugin' => ['class', [HelloGreeter::class], HelloGreeter::class.' must be a class implementing '.ExtensionPlugin::class],
]);

it('fails closed on a missing or mistyped contribution', function (): void {
    $registry = compilePlugins(PlgOwner::class);

    expect(static fn () => $registry->get('greeter', 'test.nope', Greeter::class))->toThrow(UnsupportedContribution::class, 'no registered plugin contributes this key')
        ->and(static fn () => $registry->get('missing_kind', 'x', Greeter::class))->toThrow(UnsupportedContribution::class, 'no installed plugin declares this contribution kind')
        ->and(static fn () => $registry->get('greeter', 'test.hello', Countable::class))->toThrow(UnsupportedContribution::class, 'does not implement Countable')
        ->and($registry->has('missing_kind', 'x'))->toBeFalse()
        ->and($registry->keys('missing_kind'))->toBe([]);
});

it('rejects registrar calls after register() returned', function (): void {
    PlgLate::$registrar = null;
    compilePlugins(PlgLate::class);

    expect(static fn () => PlgLate::$registrar?->contribute('core_hook', HelloGreeter::class))->toThrow(LogicException::class, 'frozen')
        ->and(static fn () => PlgLate::$registrar?->declareKind(new ContributionKind('late', Greeter::class, 'test.late')))->toThrow(LogicException::class);
});

it('builds the registry lazily, once, and freezes the catalog', function (): void {
    ExtensionRegistrar::builtIn(PlgOwner::class);

    expect(app()->resolved(Contributions::class))->toBeFalse();

    $registry = app(Contributions::class);

    expect($registry)->toBe(app(Contributions::class))->toBe(app(PluginInspector::class))
        ->and($registry->keys('greeter'))->toBe(['test.hello'])
        ->and(array_map(static fn ($p): array => [$p->id, $p->source], $registry->describe()))->toBe([['test.owner', PluginSource::BuiltIn]])
        ->and(static fn () => ExtensionRegistrar::builtIn(PlgExtension::class))->toThrow(LogicException::class, 'frozen');
});

it('merges built-in and configured plugins, built-in first', function (): void {
    config(['packman.plugins' => [PlgExtension::class]]);
    ExtensionRegistrar::builtIn(PlgOwner::class);

    $registry = app(Contributions::class);

    expect($registry->keys('greeter'))->toBe(['test.hello', 'test.hola'])
        ->and(array_map(static fn ($p): string => $p->source->value, $registry->describe()))->toBe(['builtin', 'config']);
});

it('fails the registry with the configuration error on every use', function (): void {
    config(['packman.plugins' => [PlgCycleA::class, PlgCycleB::class]]);

    expect(static fn () => app(Contributions::class))->toThrow(InvalidConfiguration::class, 'dependency cycle')
        ->and(static fn () => app(Contributions::class))->toThrow(InvalidConfiguration::class, 'dependency cycle');
});

it('lists plugins with packman:plugins and prints json', function (): void {
    ExtensionRegistrar::builtIn(PlgOwner::class);
    config(['packman.plugins' => [PlgExtension::class]]);

    $exitCode = Artisan::call('packman:plugins');
    $tableOutput = preg_replace('/\s+/', '', Artisan::output()) ?? '';

    expect($exitCode)->toBe(0)
        ->and($tableOutput)->toContain('test.owner')
        ->and($tableOutput)->toContain('test.hello')
        ->and($tableOutput)->toContain(HolaGreeter::class);

    $this->artisan('packman:plugins', ['--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('"id": "test.owner"');
});

it('fails packman:plugins with the configuration error', function (): void {
    config(['packman.plugins' => [PlgCycleA::class, PlgCycleB::class]]);

    $this->artisan('packman:plugins')->assertFailed()->expectsOutputToContain('packman.plugins.0');
});

it('does not grant contribution ownership through an optional load-order dependency', function (): void {
    expect(static fn () => compilePlugins(PlgOptionalExtension::class, PlgOwner::class))
        ->toThrow(InvalidConfiguration::class, 'which this plugin does not require');
});

it('operates purely as stateless code extensions with disabled config ids and without database state', function (): void {
    $registry = PluginGraph::compile(app(), [PlgOwner::class, PlgOptional::class], disabled: ['test.optional']);

    expect($registry->keys('greeter'))->toBe(['test.hello'])
        ->and($registry->disabledIds())->toBe(['test.optional'])
        ->and(array_map(static fn ($p): string => $p->id, $registry->describe()))->toBe(['test.owner']);

    expect(static fn () => PluginGraph::compile(app(), [PlgOwner::class], disabled: ['test.nope']))
        ->toThrow(InvalidConfiguration::class, 'PLUGIN_UNKNOWN_DISABLED test.nope');

    expect(static fn () => PluginGraph::compile(app(), [PlgOwner::class, PlgExtension::class], disabled: ['test.owner']))
        ->toThrow(InvalidConfiguration::class, "requires 'test.owner', which is disabled");
});

final class SharedGreetingA extends PlgBase
{
    protected function spec(): array
    {
        return ['shared.a'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineSlot(Greeter::class, SlotKind::Contributor, shared: true);
    }
}

final class SharedGreetingB extends PlgBase
{
    protected function spec(): array
    {
        return ['shared.b'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineSlot(Greeter::class, SlotKind::Contributor, shared: true);
    }
}

class SharedGreetingContributor extends PlgBase
{
    protected function spec(): array
    {
        return ['shared.child', ['shared.b']];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contributeSlot(Greeter::class, 'shared.hello', HelloGreeter::class);
    }
}

final class UnrelatedSharedContributor extends SharedGreetingContributor
{
    protected function spec(): array
    {
        return ['shared.unrelated'];
    }
}

final class PrivateGreeting extends PlgBase
{
    protected function spec(): array
    {
        return ['private.slot'];
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineSlot(Greeter::class, SlotKind::Handler);
    }
}

it('accepts matching shared slots from independent owners and retains the required dependency boundary', function (): void {
    $graph = PluginGraph::compile(app(), [SharedGreetingA::class, SharedGreetingB::class, SharedGreetingContributor::class]);
    expect($graph->slotContributions()[Greeter::class][0]->pluginId)->toBe('shared.child')
        ->and(fn () => PluginGraph::compile(app(), [SharedGreetingA::class, PrivateGreeting::class]))
        ->toThrow(InvalidConfiguration::class, 'is defined by both')
        ->and(fn () => PluginGraph::compile(app(), [SharedGreetingA::class, SharedGreetingB::class, UnrelatedSharedContributor::class]))
        ->toThrow(InvalidConfiguration::class, 'does not require');
});

it('supports optional stateful PluginActivationGate bindings for modular hosts', function (): void {
    config(['packman.plugins' => [PlgOwner::class, PlgOptional::class]]);

    app()->instance(PluginActivationGate::class, new class implements PluginActivationGate
    {
        public function isEnabled(string $pluginId): bool
        {
            return $pluginId === 'test.owner';
        }
    });

    $registry = app(Contributions::class);

    expect($registry->has('greeter', 'test.hello'))->toBeTrue()
        ->and(array_map(static fn ($p): string => $p->id, app(PluginInspector::class)->describe()))->toBe(['test.owner'])
        ->and($registry->disabledIds())->toBe(['test.optional']);
});

it('intersects package and host activation policies and freezes their catalog with the graph', function (): void {
    $app = app();
    $ownerGate = new class implements PluginActivationGate
    {
        public function isEnabled(string $pluginId): bool
        {
            return $pluginId !== 'test.owner';
        }
    };
    $app->instance($ownerGate::class, $ownerGate);
    ExtensionRegistrar::addActivationGate($app, $ownerGate::class);
    ExtensionRegistrar::addActivationGate($app, $ownerGate::class);
    $app->instance(PluginActivationGate::class, new class implements PluginActivationGate
    {
        public function isEnabled(string $pluginId): bool
        {
            return $pluginId !== 'test.optional';
        }
    });
    ExtensionRegistrar::builtInFor($app, PlgOwner::class, PlgOptional::class);
    expect(ExtensionRegistrar::activationGateFor($app, except: $ownerGate::class)?->isEnabled('test.optional'))->toBeFalse()
        ->and($app->make(Contributions::class)->disabledIds())->toBe(['test.optional', 'test.owner'])
        ->and(fn () => ExtensionRegistrar::addActivationGate($app, $ownerGate::class))
        ->toThrow(RegistryFrozenException::class);
});

it('exposes imperative declarations without requiring a host plugin graph', function (): void {
    $declarations = PluginRegistrar::collect(new PlgCore);
    expect($declarations->kinds()[0]->name)->toBe('core_hook')
        ->and($declarations->kinds()[0]->ownerPluginId)->toBe('test.core');
});

final readonly class ComposedPluginOptions implements OptionsFactory
{
    public function __construct(public string $mode) {}

    public static function fromArray(array $config, string $path): self
    {
        $mode = $config['mode'] ?? 'safe';

        return new self(is_string($mode) ? $mode : 'safe');
    }
}

final class ComposedDoctorCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'composed.ok';
    }

    public function title(): string
    {
        return 'Composed check';
    }

    public function run(DoctorContext $context): array
    {
        return [];
    }
}

final class ComposedFacetPlugin implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDatabaseRegistration, WithDiagnostics, WithFeatureFlags, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use DeclaresFeatureFlags;
    use OwnsSchema;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;
    use RegistersInDatabase;
    use RegistersNothing;

    public function manifest(): PluginManifest
    {
        return new PluginManifest('acme.composed', '1.0.0');
    }

    public function configRoot(): string
    {
        return 'acme_composed';
    }

    public function optionsClass(): string
    {
        return ComposedPluginOptions::class;
    }

    public function tableNouns(): array
    {
        return ['widget'];
    }

    public function definedCapabilities(): array
    {
        return [Greeter::class => 'required-single'];
    }

    public function providedCapabilities(): array
    {
        return [Greeter::class => HelloGreeter::class];
    }

    public function definedSlots(): array
    {
        return [Greeter::class => ['kind' => 'handler', 'cardinality' => 'many']];
    }

    public function slotContributions(): array
    {
        return [[
            'slot' => Greeter::class,
            'id' => 'acme.hello',
            'implementation' => HelloGreeter::class,
            'priority' => 10,
            'key' => 'hello',
        ]];
    }

    public function healthChecks(): array
    {
        return [ComposedDoctorCheck::class];
    }

    public function featureFlags(): array
    {
        return [FeatureFlagDefinition::make('composed.beta', true)];
    }
}

it('hydrates capabilities, slots, options, schema nouns, diagnostics, and DB flags from composable With* traits', function (): void {
    config(['packman.plugins' => [ComposedFacetPlugin::class], 'acme_composed' => ['mode' => 'strict']]);

    $contributions = app(Contributions::class);
    $inspector = app(PluginInspector::class);
    $capabilities = app(Capabilities::class);
    $extensions = app(Extensions::class);

    expect($contributions->databaseRegisteredPluginIds())->toBe(['acme.composed'])
        ->and($inspector->facetsOf('acme.composed')?->featureFlags[0]->key)->toBe('composed.beta')
        ->and($inspector->describe()[0]->facets?->persistsInDatabase)->toBeTrue()
        ->and($inspector->disabledIds())->toBe([])
        ->and(array_keys($inspector->slots()))->toBe([Greeter::class])
        ->and(array_keys($inspector->slotContributions()))->toBe([Greeter::class])
        ->and($capabilities->require(Greeter::class))->toBeInstanceOf(HelloGreeter::class)
        ->and($extensions->handler(Greeter::class, 'hello'))->toBeInstanceOf(HelloGreeter::class)
        ->and(app(ComposedPluginOptions::class)->mode)->toBe('strict')
        ->and(app(SchemaOwnership::class)->ownerOf('widgets'))->toBe('acme.composed')
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->matching(['composed'])))->toBe(['composed.ok']);
});

it('registers all PluginErrorCode cases in the runtime ProblemRegistry', function (): void {
    $registry = resolve(ProblemRegistry::class);

    $this->assertAllErrorCodesRegistered($registry, PluginErrorCode::class);

    $missing = $registry->fromWire('plugins.missing_extension');

    expect($missing)->toBeInstanceOf(ProblemDefinition::class)
        ->and($missing->code)->toBe(PluginErrorCode::MissingExtension)
        ->and($missing->type)->toBe('urn:packman:problem:plugins.missing_extension')
        ->and($missing->defaultStatus)->toBe(422)
        ->and($missing->category)->toBe(ErrorCategory::Unsupported)
        ->and($registry->owner(PluginErrorCode::MissingExtension))->toBe('axiomasoft/packman-plugins');

    $this->assertCodedExceptionRegistered($registry, MissingExtensionException::capability(Greeter::class), 422);
    $this->assertCodedExceptionRegistered($registry, new UnsupportedContribution('greeter', 'x', 'unknown'), 422);
    $this->assertCodedExceptionRegistered($registry, InvalidExtensionGraphException::fromProblems(['cycle']), 500);
});

it('resolves configured plugin WithConfiguration optionsClass before FrozenContributions or Contributions are resolved', function (): void {
    config([
        'packman.plugins' => [ComposedFacetPlugin::class],
        'acme_composed' => ['mode' => 'strict'],
    ]);

    expect(app()->resolved(FrozenContributions::class))->toBeFalse()
        ->and(app()->resolved(Contributions::class))->toBeFalse();

    $options = app(ComposedPluginOptions::class);

    expect($options)->toBeInstanceOf(ComposedPluginOptions::class)
        ->and($options->mode)->toBe('strict')
        ->and(app(ComposedPluginOptions::class))->toBe($options)
        ->and(app()->resolved(FrozenContributions::class))->toBeFalse()
        ->and(app()->resolved(Contributions::class))->toBeFalse();

    $contributions = app(Contributions::class);

    expect($contributions)->toBeInstanceOf(FrozenContributions::class)
        ->and(app(ComposedPluginOptions::class))->toBe($options);
});

final readonly class EarlyPluginSchemaSetProvider implements SchemaSetProvider
{
    public function sets(): array
    {
        return [
            new SchemaSet(
                package: 'acme/early-plugin',
                name: 'central',
                scope: SchemaScope::Central,
                path: __DIR__,
                version: 1,
                drivers: ['sqlite', 'pgsql'],
                lifecycle: SchemaLifecycle::Platform,
            ),
        ];
    }
}

final class EarlySchemaPlugin implements ExtensionPlugin, WithSchema
{
    use OwnsSchema;
    use RegistersNothing;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'acme.early-schema', version: '1.0.0');
    }

    public function tablePrefix(): string
    {
        return 'ear_';
    }

    public function tableNouns(): array
    {
        return ['early_item'];
    }

    public function schemaSetProviders(): array
    {
        return [EarlyPluginSchemaSetProvider::class];
    }
}

it('wires built-in plugin WithSchema providers and nouns when SchemaSets resolves before FrozenContributions', function (): void {
    ExtensionRegistrar::builtIn(EarlySchemaPlugin::class);

    expect(app()->resolved(FrozenContributions::class))->toBeFalse()
        ->and(app()->resolved(PluginInspector::class))->toBeFalse();

    $sets = app(SchemaSets::class);
    $ids = array_map(static fn (SchemaSet $set): string => $set->id(), $sets->all());

    expect($ids)->toContain('acme/early-plugin:central')
        ->and(app(SchemaOwnership::class)->ownerOf('early_items'))->toBe('acme.early-schema');
});
