<?php

declare(strict_types=1);

use Filament\Panel as FilamentPanel;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ScopeRef;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Filament\Exceptions\Binding\InvalidBindingStateException;
use Packman\Filament\Internal\Configuration\FilamentOptions;
use Packman\Filament\PackmanFilamentPlugin;
use Packman\Filament\Tests\Support\FilamentTestOrderResource;
use Packman\Filament\Tests\Support\FilamentTestOverriddenResource;
use Packman\Filament\Tests\Support\FilamentTestProduct;
use Packman\Filament\Tests\Support\FilamentTestUnprotectedResource;
use Packman\Filament\Tests\Support\FilamentTestUser;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider;
use Packman\Panels\Contracts\Admission\PanelAdmissionGate;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Enums\Admission\TenantMode;
use Packman\Panels\Internal\Catalogue\PanelRegistryImpl;

class PluginTestPanelUserModel extends Model {}

class PluginTestActivityResource extends Filament\Resources\Resource
{
    protected static ?string $model = FilamentTestProduct::class;

    protected static ?string $slug = 'activities';

    public static function getPages(): array
    {
        return [];
    }
}

beforeEach(function (): void {
    $this->createFilamentTables();
});

it('registers enabled resources gated by both config toggle and optional subsystem feature flag', function (): void {
    $activityFeature = true;

    $plugin = PackmanFilamentPlugin::make()
        ->resource('orders', FilamentTestOrderResource::class)
        ->resource('activity', PluginTestActivityResource::class, static function () use (&$activityFeature): bool {
            return $activityFeature;
        });

    $panelA = FilamentPanel::make()->id('probe-on')->path('probe-on')->plugin($plugin);
    expect($panelA->getResources())->toContain(FilamentTestOrderResource::class)
        ->toContain(PluginTestActivityResource::class);

    // Config toggle disables resource.
    config()->set('packman-filament.resources.activity', false);
    app()->forgetInstance(FilamentOptions::class);

    $pluginConfigOff = PackmanFilamentPlugin::make()
        ->resource('orders', FilamentTestOrderResource::class)
        ->resource('activity', PluginTestActivityResource::class, static fn (): bool => true);
    $panelB = FilamentPanel::make()->id('probe-cfg-off')->path('probe-cfg-off')->plugin($pluginConfigOff);
    expect($panelB->getResources())->not->toContain(PluginTestActivityResource::class)
        ->toContain(FilamentTestOrderResource::class);

    // Malformed non-bool config fails open to documented default (true), while feature flag false hides it.
    config()->set('packman-filament.resources.activity', 'not-a-bool');
    app()->forgetInstance(FilamentOptions::class);
    $activityFeature = false;

    $pluginFeatureOff = PackmanFilamentPlugin::make()
        ->resource('orders', FilamentTestOrderResource::class)
        ->resource('activity', PluginTestActivityResource::class, static function () use (&$activityFeature): bool {
            return $activityFeature;
        });
    $panelC = FilamentPanel::make()->id('probe-feat-off')->path('probe-feat-off')->plugin($pluginFeatureOff);
    expect($panelC->getResources())->not->toContain(PluginTestActivityResource::class)
        ->toContain(FilamentTestOrderResource::class);
});

it('resolves current() safely, attaches middleware respecting tenancy order, and validates enforced resources', function (): void {
    $panel = FilamentPanel::make()
        ->id('admin')
        ->default()
        ->path('admin')
        ->tenant(PluginTestPanelUserModel::class)
        ->resources([FilamentTestOrderResource::class])
        ->plugin(
            PackmanFilamentPlugin::make()
                ->enforce()
                ->persistentMiddleware(['web']),
        );

    $this->activatePanel($panel);

    $resolved = PackmanFilamentPlugin::current($panel);
    expect($resolved->getId())->toBe('packman')
        ->and($resolved->isEnforced())->toBeTrue()
        ->and(PackmanFilamentPlugin::get('admin'))->toBe($resolved)
        ->and($panel->getTenantMiddleware())->toContain('web');

    // Enforcing an unprotected resource must fail boot.
    $badPlugin = PackmanFilamentPlugin::make()->enforce();
    $badPanel = FilamentPanel::make()
        ->id('bad-unprotected')
        ->path('bad-unprotected')
        ->resources([FilamentTestUnprotectedResource::class])
        ->plugin($badPlugin);

    expect(fn () => $badPlugin->boot($badPanel))->toThrow(InvalidBindingStateException::class, 'AuthorizesResource');

    // Enforcing a resource that overrides trait methods must fail boot.
    $overriddenPanel = FilamentPanel::make()
        ->id('bad-overridden')
        ->path('bad-overridden')
        ->resources([FilamentTestOverriddenResource::class])
        ->plugin($badPlugin);

    expect(fn () => $badPlugin->boot($overriddenPanel))->toThrow(InvalidBindingStateException::class, 'overriding');

    // Declaring ->tenant() AFTER ->plugin() must fail boot.
    $lateTenantPlugin = PackmanFilamentPlugin::make();
    $latePanel = FilamentPanel::make()->id('late-tenant')->path('late-tenant')->plugin($lateTenantPlugin);
    $latePanel->tenant(PluginTestPanelUserModel::class);

    expect(fn () => $lateTenantPlugin->boot($latePanel))->toThrow(InvalidBindingStateException::class, 'tenant(...) before');
});

it('fails boot when enforced panel names an unregistered guardPanel', function (): void {
    expect(app()->make(PanelRegistry::class)->isFrozen())->toBeTrue();

    $plugin = PackmanFilamentPlugin::make()->guardPanel('nonexistent')->enforce();
    $panel = FilamentPanel::make()->id('unknown-guard')->path('unknown-guard')->plugin($plugin);

    expect(fn () => $plugin->boot($panel))->toThrow(InvalidBindingStateException::class, 'unknown guard panel');
});

class TenantRequiredGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'tenant-guard';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->for(FilamentTestUser::class)
            ->tenantMode(TenantMode::Required);
    }
}

it('passes active ContextResolverInterface scope to PanelAdmitter for TenantMode::Required panels and falls back to global when absent', function (): void {
    $observedScopes = [];
    $gate = new class($observedScopes) implements PanelAdmissionGate
    {
        /** @param list<string> $observedScopes */
        public function __construct(private array &$observedScopes) {}

        public function isMember(Panel $panel, object $subject, ContextScopeInterface $tenant, ?string $scopeType = null, ?string $scopeId = null): bool
        {
            $this->observedScopes[] = $tenant->id();

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
    };

    $originalRegistry = app()->make(PanelRegistry::class);
    $registry = new PanelRegistryImpl(app());
    $registry->register(TenantRequiredGuardPanelProvider::class);
    $registry->freeze();

    app()->instance(PanelRegistry::class, $registry);
    app()->instance(PanelAdmissionGate::class, $gate);
    app()->forgetInstance(FilamentSurfaceGate::class);

    $activeScope = new ScopeRef('tenant-42');
    $resolver = new class($activeScope) implements ContextResolverInterface
    {
        public function __construct(public ?ContextScopeInterface $scope) {}

        public function current(): ?ContextScopeInterface
        {
            return $this->scope;
        }
    };
    app()->instance(ContextResolverInterface::class, $resolver);

    try {
        $filamentPanel = FilamentPanel::make()
            ->id('tenant-admin')
            ->default()
            ->path('tenant-admin')
            ->resources([FilamentTestOrderResource::class])
            ->plugin(PackmanFilamentPlugin::make()->guardPanel('tenant-guard')->enforce());

        $this->activatePanel($filamentPanel);

        $user = FilamentTestUser::query()->create(['id' => 1, 'name' => 'Tenant User']);
        $this->actingAs($user);
        Gate::define('orders.view_any', static fn (): bool => true);

        expect(FilamentTestOrderResource::canViewAny())->toBeTrue()
            ->and($observedScopes)->toBe(['tenant-42']);

        $resolver->scope = null;

        expect(FilamentTestOrderResource::canViewAny())->toBeFalse();
    } finally {
        app()->instance(PanelRegistry::class, $originalRegistry);
        app()->forgetInstance(FilamentSurfaceGate::class);
    }
});
