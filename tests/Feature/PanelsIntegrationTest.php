<?php

declare(strict_types=1);

use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Packman\Context\Data\ScopeRef;
use Packman\Errors\Internal\CompiledProblemRegistry;
use Packman\Errors\Internal\ProblemContribution;
use Packman\Extensions\Concerns\Composition\ProvidesPanels;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\ExtensionInspector;
use Packman\Modules\Contracts\Definition\ModuleDefinition;
use Packman\Modules\Contracts\Entities\EntityRegistry;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Data\Lifecycle\ModuleManifest;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Modules\ModulesServiceProvider;
use Packman\PackmanServiceProvider;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider as BuilderPanelProvider;
use Packman\Panels\Contracts\Registry\PanelCatalogue;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;
use Packman\Panels\Data\Subjects\EntityReference;
use Packman\Panels\Enums\PanelErrorCode;
use Packman\Panels\Internal\Admission\PanelsProblemDefinitions;
use Packman\Panels\Internal\Catalogue\InMemoryPanelCatalogue;
use Packman\Panels\Internal\Catalogue\ModulePanelCatalogue;
use Packman\Panels\Internal\Catalogue\PanelRegistryImpl;
use Packman\Panels\Laravel\PanelExtensionBridge;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Plugins\Concerns\RegistersNothing;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginsServiceProvider;

class ExtensionBridgeBuilderProvider extends BuilderPanelProvider
{
    public static function getId(): string
    {
        return 'ext-builder';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->label('From Extension');
    }
}

class ExtensionBridgeCatalogueProvider implements PanelProvider
{
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor('panel/1', 'ext.panel', 'acme/ext', null, ['open'], 'Extension Panel');
    }

    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return new PanelAccess(true, ['open'], null);
    }
}

class WithPanelsConsumer implements WithPanels
{
    use ProvidesPanels;

    /** @return list<class-string> */
    public function panelProviders(): array
    {
        return [
            ExtensionBridgeBuilderProvider::class,
            ExtensionBridgeCatalogueProvider::class,
        ];
    }
}

it('resolves PanelCatalogue standalone with InMemoryPanelCatalogue when ModuleRegistry is unbound', function (): void {
    $app = app();
    $app->offsetUnset(ModuleRegistry::class);
    $app->offsetUnset(EntityRegistry::class);

    $provider = new ExtensionBridgeCatalogueProvider;
    $app->instance('test.standalone.panel', $provider);
    $app->tag(['test.standalone.panel'], 'packman.panels.providers');

    $app->forgetInstance(PanelCatalogue::class);

    $catalogue = $app->make(PanelCatalogue::class);

    expect($catalogue)->toBeInstanceOf(InMemoryPanelCatalogue::class)
        ->and($catalogue)->not->toBeInstanceOf(ModulePanelCatalogue::class)
        ->and($catalogue->forEntity(new PanelContext(new ScopeRef('t-1'), 'guest', null), new EntityReference('order', '1')))
        ->toHaveCount(1);
});

it('registers WithPanels consumers into PanelRegistry and tagged PanelCatalogue', function (): void {
    $originalRegistry = app()->make(PanelRegistry::class);
    $registry = new PanelRegistryImpl(app());
    app()->instance(PanelRegistry::class, $registry);

    try {
        $consumer = new WithPanelsConsumer;
        $profile = ExtensionInspector::inspect($consumer);

        expect($profile->panelProviders)->toBe([
            ExtensionBridgeBuilderProvider::class,
            ExtensionBridgeCatalogueProvider::class,
        ]);

        PanelExtensionBridge::registerExtension(app(), $consumer);
        $registry->freeze();

        $tagged = iterator_to_array(app()->tagged('packman.panels.providers'));

        expect($registry->get('ext-builder')->label())->toBe('From Extension')
            ->and(array_filter($tagged, static fn (mixed $p): bool => $p instanceof ExtensionBridgeCatalogueProvider))
            ->not->toBeEmpty();
    } finally {
        app()->instance(PanelRegistry::class, $originalRegistry);
    }
});

it('exposes PanelErrorCode and PanelsProblemDefinitions cleanly for compiled problem registries', function (): void {
    $registry = new CompiledProblemRegistry([
        new ProblemContribution(
            provider: new PanelsProblemDefinitions,
            owner: 'panels',
        ),
    ]);

    foreach (PanelErrorCode::cases() as $case) {
        expect(str_starts_with($case->value(), 'panels.'))->toBeTrue()
            ->and($registry->get($case)->code)->toBe($case);
    }
});

class ConfiguredPluginBuilderProvider extends BuilderPanelProvider
{
    public static function getId(): string
    {
        return 'plugin-boot';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->label('Plugin Boot Panel');
    }
}

class ConfiguredModuleBuilderProvider extends BuilderPanelProvider
{
    public static function getId(): string
    {
        return 'module-boot';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->label('Module Boot Panel');
    }
}

class ConfiguredWithPanelsPlugin implements ExtensionPlugin, WithPanels
{
    use ProvidesPanels;
    use RegistersNothing;

    public function manifest(): PluginManifest
    {
        return new PluginManifest('acme.with-panels', '1.0.0');
    }

    /** @return list<class-string> */
    public function panelProviders(): array
    {
        return [
            ConfiguredPluginBuilderProvider::class,
            ExtensionBridgeCatalogueProvider::class,
        ];
    }
}

class DiscoveredWithPanelsModule extends ModuleDefinition implements WithPanels
{
    use ProvidesPanels;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('acme/with-panels-module')
            ->title('With Panels Module')
            ->tablePrefix('wpm');
    }

    /** @return list<class-string> */
    public function panelProviders(): array
    {
        return [ConfiguredModuleBuilderProvider::class];
    }
}

it('registers configured WithPanels plugin and discovered module panels into PanelRegistry before freeze on normal boot', function (): void {
    $previousContainer = Container::getInstance();
    $previousFacadeApp = Facade::getFacadeApplication();

    $freshApp = new Application(basePath: $this->app->basePath());
    $freshApp->instance('env', 'testing');
    $freshApp->instance('config', new ConfigRepository([
        'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        'packman' => ['plugins' => [ConfiguredWithPanelsPlugin::class]],
    ]));
    $freshApp->register(EventServiceProvider::class);
    $freshApp->register(FilesystemServiceProvider::class);
    $freshApp->register(DatabaseServiceProvider::class);
    $freshApp->register(CacheServiceProvider::class);
    $freshApp->register(PackmanServiceProvider::class);
    $freshApp->register(PluginsServiceProvider::class);
    $freshApp->register(ModulesServiceProvider::class);
    $freshApp->register(PanelsServiceProvider::class);
    $freshApp->instance(
        ModuleDiscovery::class,
        new ModuleDiscovery(fallbackModules: [DiscoveredWithPanelsModule::class]),
    );

    try {
        $freshApp->boot();

        $registry = $freshApp->make(PanelRegistry::class);
        $tagged = iterator_to_array($freshApp->tagged('packman.panels.providers'));

        expect($registry->isFrozen())->toBeTrue()
            ->and($registry->find('plugin-boot')?->label())->toBe('Plugin Boot Panel')
            ->and($registry->find('module-boot')?->label())->toBe('Module Boot Panel')
            ->and(array_filter($tagged, static fn (mixed $p): bool => $p instanceof ExtensionBridgeCatalogueProvider))
            ->toHaveCount(1);
    } finally {
        Container::setInstance($previousContainer);
        Facade::setFacadeApplication($previousFacadeApp);
    }
});
