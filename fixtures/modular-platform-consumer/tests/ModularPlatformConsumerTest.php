<?php

declare(strict_types=1);

namespace Fixture\ModularPlatform\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Panel as FilamentPanel;
use Filament\PanelRegistry as FilamentPanelRegistry;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Resources\Resource;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Fixture\CrmModule\CrmModule;
use Fixture\ModularPlatform\Filament\Contact;
use Fixture\ModularPlatform\Filament\ContactResource;
use Fixture\ModularPlatform\Filament\ContactUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Packman\Context\Contracts\ActorResolverInterface;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ActorRef;
use Packman\Context\Data\ScopeRef;
use Packman\Extensions\ExtensionInspector;
use Packman\Filament\Exceptions\Binding\InvalidBindingStateException;
use Packman\Filament\PackmanFilamentPlugin;
use Packman\Filament\PackmanFilamentServiceProvider;
use Packman\Modules\Contracts\Definition\ModuleDefinition;
use Packman\Modules\Contracts\Lifecycle\ModuleLifecycle;
use Packman\Modules\Contracts\Lifecycle\RecordsRegistrar;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Contracts\Registry\ModuleStateReader;
use Packman\Modules\Data\Compiler\CompiledModule;
use Packman\Modules\Data\Compiler\InstalledPackageVersion;
use Packman\Modules\Enums\Lifecycle\DisableReason;
use Packman\Modules\Enums\Lifecycle\ModuleState;
use Packman\Modules\Internal\Compiler\RegistryCompiler;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Modules\Internal\Registry\CompiledModuleRegistry;
use Packman\Modules\Internal\Support\ModulesOptions;
use Packman\Modules\ModulesServiceProvider;
use Packman\PackmanServiceProvider;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Contracts\Admission\PanelAdmissionGate;
use Packman\Panels\Contracts\Registry\PanelCatalogue;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Internal\Catalogue\ModulePanelCatalogue;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Testkit\Concerns\Conformance\ModuleContractTests;
use Packman\Testkit\Concerns\Database\RefreshesDatabaseForSchemaVariant;
use Packman\Testkit\PackageTestCase;
use Packman\Testkit\Support\Runtime\TestGroups;
use ReflectionClass;
use UnitEnum;

final class UnprotectedContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static ?string $slug = 'unprotected-contacts';

    public static function getPages(): array
    {
        return [];
    }
}

final class ModularPlatformConsumerTest extends PackageTestCase
{
    use ModuleContractTests;
    use RefreshesDatabaseForSchemaVariant;

    protected function moduleUnderTest(): ModuleDefinition
    {
        return new CrmModule;
    }

    /** @return array<string, InstalledPackageVersion> */
    protected function installedExtensionPackages(): array
    {
        return [
            'axiomasoft/packman' => new InstalledPackageVersion('axiomasoft/packman', '0.1.0', null),
            'axiomasoft/packman-modules' => new InstalledPackageVersion('axiomasoft/packman-modules', '0.1.0', null),
            'fixture/crm-module' => new InstalledPackageVersion('fixture/crm-module', '0.1.0', null),
        ];
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            SchemaServiceProvider::class,
            ModulesServiceProvider::class,
            PanelsServiceProvider::class,
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            ActionsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            PackmanFilamentServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $fixtureRoot = dirname(__DIR__);
        $installedJson = $fixtureRoot.'/vendor/composer/installed.json';
        $cacheDir = $fixtureRoot.'/bootstrap/cache';

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $app['config']->set('session.driver', 'array');
        $app['config']->set('auth.providers.users.model', ContactUser::class);
        $app['config']->set('modules.cache_path', 'bootstrap/cache/modules.php');

        $compiledViews = sys_get_temp_dir().'/packman_fixture_views_'.getmypid();

        if (! is_dir($compiledViews)) {
            mkdir($compiledViews, 0775, true);
        }
        $app['config']->set('view.compiled', $compiledViews);

        $app->singletonIf(ContextResolverInterface::class, static fn (): ContextResolverInterface => new readonly class implements ContextResolverInterface
        {
            public function current(): ContextScopeInterface
            {
                return new ScopeRef('tenant-1');
            }
        });
        $app->singletonIf(ActorResolverInterface::class, static fn (): ActorResolverInterface => new readonly class implements ActorResolverInterface
        {
            public function current(): ActorRef
            {
                return new ActorRef('user', '1');
            }
        });
        $app->singleton(PanelAdmissionGate::class, static fn (): PanelAdmissionGate => new class implements PanelAdmissionGate
        {
            public function isMember(Panel $panel, object $subject, ContextScopeInterface $tenant, ?string $scopeType = null, ?string $scopeId = null): bool
            {
                return true;
            }

            public function hasRole(Panel $panel, object $subject, ContextScopeInterface $tenant, ?string $scopeType = null, ?string $scopeId = null): bool
            {
                return true;
            }

            public function childScopesWithRole(Panel $panel, object $subject, ContextScopeInterface $tenant, string $scopeType): array
            {
                return [];
            }

            public function allowsEntry(Panel $panel, object $subject, ContextScopeInterface $tenant, string|UnitEnum $permission, ?string $scopeType = null, ?string $scopeId = null): bool
            {
                return true;
            }
        });

        $app->singleton(ModuleDiscovery::class, static function (Application $app) use ($installedJson): ModuleDiscovery {
            $options = $app->make(ModulesOptions::class);

            return new ModuleDiscovery(
                installedJsonPath: $installedJson,
                packageTypes: $options->packageTypes,
                extraKeys: $options->extraKeys,
            );
        });
        $app->when(RegistryCompiler::class)->needs('$installedJsonPath')->give($installedJson);
    }

    public function test_composer_discovered_module_compiles_with_facets_panels_schema_and_filament(): void
    {
        $fixtureRoot = dirname(__DIR__);
        $installedJson = $fixtureRoot.'/vendor/composer/installed.json';

        // 1. Composer-discovered ModuleDefinition + ExtensionInspector facets
        $discovery = $this->app->make(ModuleDiscovery::class);
        $manifests = $discovery->discover();

        self::assertCount(1, $manifests);
        $manifest = $manifests[0];
        self::assertSame('fixture/crm-module', $manifest->composerName);
        self::assertSame('crm', $manifest->tablePrefix);
        self::assertCount(2, $manifest->settings);
        self::assertTrue($manifest->settings[1]->sensitive);
        self::assertCount(1, $manifest->featureFlags);

        $profile = ExtensionInspector::inspect(new CrmModule);
        self::assertTrue($profile->hasConfiguration);
        self::assertTrue($profile->hasDatabaseSettings);
        self::assertTrue($profile->persistsInDatabase);
        self::assertTrue($profile->hasSchema);
        self::assertCount(1, $profile->panelProviders);

        // 2. RegistryCompiler + atomic CompiledModuleRegistry artifact in bootstrap/cache/modules.php
        $compiler = $this->app->make(RegistryCompiler::class);
        $compiled = $compiler->compile($manifests);

        self::assertArrayHasKey('crm.contact', $compiled->entities);
        self::assertCount(2, $compiled->settings);
        self::assertCount(1, $compiled->featureFlags);

        $cacheFile = $fixtureRoot.'/bootstrap/cache/modules.php';
        $testbenchCacheFile = $this->app->basePath($this->app->make(ModulesOptions::class)->cachePath);
        $compiledModules = array_map(CompiledModule::fromManifest(...), $manifests);
        CompiledModuleRegistry::writeAtomic($cacheFile, $compiledModules, $compiled);
        CompiledModuleRegistry::writeAtomic($testbenchCacheFile, $compiledModules, $compiled);
        self::assertFileExists($cacheFile);

        try {
            $registry = $this->app->make(ModuleRegistry::class);
            self::assertInstanceOf(CompiledModuleRegistry::class, $registry);
            $registry->flush();

            self::assertSame($compiled->fingerprint, $registry->compiled()->fingerprint);
            self::assertArrayHasKey('crm.contact', $registry->compiled()->entities);
            self::assertCount(1, $registry->all());
        } finally {
            @unlink($testbenchCacheFile);
        }

        // 3. SchemaSets registered for both modules and panels; panels SQLite migration runs cleanly
        $sets = $this->app->make(SchemaSets::class);
        $setIds = array_map(static fn ($set): string => $set->id(), $sets->all());
        self::assertContains('axiomasoft/packman-modules:tenant', $setIds);
        self::assertContains('axiomasoft/packman-panels:tenant', $setIds);

        $ran = $this->app->make(SchemaMigrator::class)->migrateSets(['axiomasoft/packman-panels:tenant']);
        self::assertNotEmpty($ran);
        self::assertTrue(Schema::hasTable('sys_saved_views'));
        self::assertTrue(Schema::hasTable('sys_saved_view_scopes'));

        // 4. Panels integrated with Modules (ModulePanelCatalogue + frozen PanelRegistry containing crm-admin)
        $panelRegistry = $this->app->make(PanelRegistry::class);
        self::assertTrue($panelRegistry->isFrozen());
        self::assertSame('CRM Admin', $panelRegistry->get('crm-admin')->label());
        self::assertInstanceOf(ModulePanelCatalogue::class, $this->app->make(PanelCatalogue::class));

        // 5. PackmanFilamentPlugin integrates with enforced resource authorization and guardPanel
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $filamentPanel = FilamentPanel::make()
            ->id('app')
            ->default()
            ->path('app')
            ->plugin(
                PackmanFilamentPlugin::make()
                    ->guardPanel('crm-admin')
                    ->enforce()
                    ->resource('contacts', ContactResource::class),
            );

        $this->app->make(FilamentPanelRegistry::class)->register($filamentPanel);
        $routes = dirname((string) (new ReflectionClass(FilamentServiceProvider::class))->getFileName()).'/../routes/web.php';

        if (is_file($routes)) {
            require $routes;
            $this->app->make(Router::class)->getRoutes()->refreshNameLookups();
        }
        Filament::setCurrentPanel($filamentPanel);
        Filament::bootCurrentPanel();

        $resolvedPlugin = PackmanFilamentPlugin::current($filamentPanel);
        self::assertTrue($resolvedPlugin->isEnforced());
        self::assertSame('crm-admin', $resolvedPlugin->getGuardPanel());
        self::assertContains(ContactResource::class, $filamentPanel->getResources());

        $user = ContactUser::query()->create(['name' => 'Admin']);
        $this->actingAs($user);
        Gate::define('contacts.view_any', static fn (): bool => true);
        self::assertTrue(ContactResource::canViewAny());

        $badPlugin = PackmanFilamentPlugin::make()->guardPanel('crm-admin')->enforce();
        $badPanel = FilamentPanel::make()
            ->id('bad')
            ->path('bad')
            ->resources([UnprotectedContactResource::class])
            ->plugin($badPlugin);

        $this->expectException(InvalidBindingStateException::class);
        $this->expectExceptionMessage('AuthorizesResource');
        $badPlugin->boot($badPanel);
    }

    public function test_module_schema_migration_and_lifecycle_run_with_non_uuid_actor_ref(): void
    {
        if (! TestGroups::hasPgsql()) {
            $this->markTestSkipped('PostgreSQL test database is required for ModuleLifecycle.');
        }

        $this->resetPgsqlPublicSchema('pgsql');

        $app = $this->app;
        assert($app instanceof Application);

        $app['config']->set('modules.connection', 'pgsql');
        $app->forgetInstance(ModulesOptions::class);
        $app->forgetInstance(ModuleStateReader::class);
        $app->forgetInstance(RecordsRegistrar::class);
        $app->forgetInstance(ModuleRegistry::class);
        $app->forgetInstance(ModuleLifecycle::class);

        $manifests = $app->make(ModuleDiscovery::class)->discover();
        $compiled = $app->make(RegistryCompiler::class)->compile($manifests);
        $compiledModules = array_map(CompiledModule::fromManifest(...), $manifests);
        $testbenchCacheFile = $app->basePath($app->make(ModulesOptions::class)->cachePath);
        CompiledModuleRegistry::writeAtomic($testbenchCacheFile, $compiledModules, $compiled);

        try {
            $ran = $app->make(SchemaMigrator::class)->migrateSets(['axiomasoft/packman-modules:tenant'], 'pgsql');
            self::assertNotEmpty($ran);
            self::assertTrue(Schema::connection('pgsql')->hasTable('mod_modules'));
            self::assertTrue(Schema::connection('pgsql')->hasTable('mod_lifecycle_log'));

            $lifecycle = $app->make(ModuleLifecycle::class);

            $lifecycle->enable('fixture/crm-module');
            $enabledStatus = $lifecycle->status('fixture/crm-module');
            self::assertSame(ModuleState::Enabled, $enabledStatus->state);

            $moduleRow = DB::connection('pgsql')->table('mod_modules')->where('name', 'fixture/crm-module')->first();
            self::assertNotNull($moduleRow);
            self::assertSame('enabled', $moduleRow->state);

            $enableLog = DB::connection('pgsql')
                ->table('mod_lifecycle_log')
                ->where('module_name', 'fixture/crm-module')
                ->where('operation', 'enable')
                ->first();
            self::assertNotNull($enableLog);
            self::assertSame('user', $enableLog->actor_type);
            self::assertSame('1', $enableLog->actor_id);

            $lifecycle->disable('fixture/crm-module', DisableReason::Manual);
            $disabledStatus = $lifecycle->status('fixture/crm-module');
            self::assertSame(ModuleState::Disabled, $disabledStatus->state);

            $moduleAfterDisable = DB::connection('pgsql')->table('mod_modules')->where('name', 'fixture/crm-module')->first();
            self::assertNotNull($moduleAfterDisable);
            self::assertSame('disabled', $moduleAfterDisable->state);
            self::assertSame('manual', $moduleAfterDisable->disable_reason);

            $disableLog = DB::connection('pgsql')
                ->table('mod_lifecycle_log')
                ->where('module_name', 'fixture/crm-module')
                ->where('operation', 'disable')
                ->first();
            self::assertNotNull($disableLog);
            self::assertSame('user', $disableLog->actor_type);
            self::assertSame('1', $disableLog->actor_id);
        } finally {
            @unlink($testbenchCacheFile);
        }
    }
}
