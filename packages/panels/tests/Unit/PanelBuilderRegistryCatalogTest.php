<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider;
use Packman\Panels\Contracts\Registry\PanelPlugin;
use Packman\Panels\Data\Admission\PermissionDefinition;
use Packman\Panels\Data\Panel\PanelPluginContext;
use Packman\Panels\Data\Panel\PanelSlot;
use Packman\Panels\Enums\Admission\AssignmentScopeMode;
use Packman\Panels\Enums\Admission\TenantMode;
use Packman\Panels\Enums\Panel\Reads;
use Packman\Panels\Enums\Panel\StateRefresh;
use Packman\Panels\Exceptions\Definition\DefaultPanelConflictException;
use Packman\Panels\Exceptions\Definition\DefinitionException;
use Packman\Panels\Exceptions\Definition\DuplicatePanelException;
use Packman\Panels\Exceptions\Definition\InvalidPanelIdException;
use Packman\Panels\Exceptions\Definition\PluginConflictException;
use Packman\Panels\Exceptions\Definition\PrefixConflictException;
use Packman\Panels\Exceptions\Definition\UnknownSlotException;
use Packman\Panels\Exceptions\Permissions\DuplicatePermissionException;
use Packman\Panels\Exceptions\Permissions\DuplicateRoleException;
use Packman\Panels\Exceptions\Permissions\UnknownPermissionException;
use Packman\Panels\Exceptions\Registry\RegistryFrozenException;
use Packman\Panels\Exceptions\Registry\UnknownPanelException;
use Packman\Panels\Internal\Catalogue\PanelCatalog;
use Packman\Panels\Internal\Catalogue\PanelCompiler;
use Packman\Panels\Internal\Catalogue\PanelFingerprint;
use Packman\Panels\Internal\Catalogue\PanelRecipe;
use Packman\Panels\Internal\Catalogue\PanelRegistryImpl;

enum PanelTestOrderPermission: string
{
    case View = 'orders.view';
    case Update = 'orders.update';
}

enum PanelTestNonStringEnum: int
{
    case One = 1;
}

class PanelTestUser extends Model {}

class PanelTestManager extends PanelTestUser {}

class PanelTestSeller extends Model {}

class PanelTestAdminProvider extends PanelProvider
{
    public static string $label = 'Admin';

    public static bool $default = false;

    public static string|bool $prefix = true;

    public static ?Closure $custom = null;

    public static function getId(): string
    {
        return 'admin';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        $panel->label(self::$label)->default(self::$default)->resourcePrefix(self::$prefix);

        if (self::$custom !== null) {
            (self::$custom)($panel);
        }

        return $panel;
    }
}

class PanelTestAdminReplacementProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'admin';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->label('Replacement Admin')->for(PanelTestUser::class);
    }
}

class PanelTestCabinetProvider extends PanelProvider
{
    public static bool $default = false;

    public static string|bool $prefix = true;

    public static function getId(): string
    {
        return 'cabinet';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->label('Cabinet')
            ->default(self::$default)
            ->resourcePrefix(self::$prefix)
            ->for(PanelTestUser::class);
    }
}

class PanelTestBadIdProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'Invalid_Id';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel;
    }
}

final readonly class PanelTestOptions
{
    public function __construct(public string $mode, public int $ttl = 60) {}
}

final class PanelTestOptionsPlugin implements PanelPlugin
{
    public function __construct(
        private string $id = 'test/options',
        private string $prefix = 'opt',
        private string $mode = 'fast',
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function register(PanelBuilder $panel, PanelPluginContext $context): void
    {
        $panel
            ->resourcePrefix($this->prefix)
            ->options(new PanelTestOptions($this->mode))
            ->slots([new PanelSlot('read', ['timeout.ms'], ['timeout.ms' => 100])]);
    }
}

beforeEach(function (): void {
    PanelTestAdminProvider::$label = 'Admin';
    PanelTestAdminProvider::$default = false;
    PanelTestAdminProvider::$prefix = true;
    PanelTestAdminProvider::$custom = null;
    PanelTestCabinetProvider::$default = false;
    PanelTestCabinetProvider::$prefix = true;
});

it('builds, compiles, fingerprints, and freezes panels through the two-phase PanelRegistry', function (): void {
    $registry = new PanelRegistryImpl(app());
    $registry->register(PanelTestAdminProvider::class);
    $registry->register(PanelTestCabinetProvider::class);
    $registry->replace(PanelTestAdminReplacementProvider::class);
    $registry->configure('cabinet', static fn (PanelBuilder $panel): PanelBuilder => $panel->consistency(Reads::Primary, StateRefresh::Check));

    expect($registry->isFrozen())->toBeFalse()
        ->and(fn (): array => $registry->all())->toThrow(DefinitionException::class, 'not compiled yet');

    $registry->freeze();

    expect($registry->isFrozen())->toBeTrue()
        ->and(array_keys($registry->all()))->toBe(['admin', 'cabinet'])
        ->and($registry->get('admin')->label())->toBe('Replacement Admin')
        ->and($registry->find('missing'))->toBeNull()
        ->and($registry->forModel(PanelTestUser::class))->toHaveCount(2)
        ->and($registry->defaultFor(PanelTestUser::class))->toBeNull()
        ->and($registry->get('admin')->accepts(new PanelTestManager))->toBeTrue()
        ->and($registry->get('admin')->accepts(new PanelTestSeller))->toBeFalse()
        ->and($registry->get('cabinet')->settings()->reads())->toBe(Reads::Primary)
        ->and($registry->get('cabinet')->settings()->stateRefresh())->toBe(StateRefresh::Check);

    expect(fn () => $registry->register(PanelTestAdminProvider::class))->toThrow(RegistryFrozenException::class)
        ->and(fn () => $registry->configure('admin', static fn (PanelBuilder $p): PanelBuilder => $p))->toThrow(RegistryFrozenException::class)
        ->and(fn () => $registry->get('unknown'))->toThrow(UnknownPanelException::class);
});

it('rejects duplicate panels, invalid panel ids, conflicting defaults, conflicting prefixes, and conflicting plugin values', function (): void {
    $dup = new PanelRegistryImpl(app());
    $dup->register(PanelTestAdminProvider::class);
    expect(fn () => $dup->register(PanelTestAdminReplacementProvider::class))->toThrow(DuplicatePanelException::class)
        ->and(fn () => $dup->register(PanelTestBadIdProvider::class))->toThrow(InvalidPanelIdException::class);

    PanelTestAdminProvider::$default = true;
    PanelTestCabinetProvider::$default = true;
    PanelTestAdminProvider::$custom = static fn (PanelBuilder $p): PanelBuilder => $p->for(PanelTestUser::class);

    $conflictDefault = new PanelRegistryImpl(app());
    $conflictDefault->register(PanelTestAdminProvider::class);
    $conflictDefault->register(PanelTestCabinetProvider::class);
    expect(fn () => $conflictDefault->freeze())->toThrow(DefaultPanelConflictException::class);

    PanelTestAdminProvider::$default = false;
    PanelTestCabinetProvider::$default = false;
    PanelTestAdminProvider::$prefix = 'shared';
    PanelTestCabinetProvider::$prefix = 'shared';

    $conflictPrefix = new PanelRegistryImpl(app());
    $conflictPrefix->register(PanelTestAdminProvider::class);
    $conflictPrefix->register(PanelTestCabinetProvider::class);
    expect(fn () => $conflictPrefix->freeze())->toThrow(PrefixConflictException::class);

    $recipe = new PanelRecipe('admin');
    $builder = new PanelBuilder($recipe);
    $builder->plugins([
        new PanelTestOptionsPlugin('acme/one', 'one', 'a'),
        new PanelTestOptionsPlugin('acme/two', 'two', 'b'),
    ]);
    $compiler = new PanelCompiler;
    $compiler->register($recipe, $builder, app(), 'test');

    expect(fn (): Panel => $compiler->compile($recipe))->toThrow(PluginConflictException::class);
});

it('enforces layer precedence, typed options, slots, and tenant/scope metadata on compiled Panel', function (): void {
    $recipe = new PanelRecipe('admin');
    $builder = new PanelBuilder($recipe);
    $recipe->during(PanelRecipe::configure(), static fn () => $builder->label('Config'));
    $recipe->during(PanelRecipe::configureAll(), static fn () => $builder->description('Configured All'));
    $builder->plugins([new PanelTestOptionsPlugin('acme/opt', 'plugin-prefix', 'strict')])
        ->label('Provider')
        ->description('Provider description')
        ->tenantMode(TenantMode::Required)
        ->scopeMode(AssignmentScopeMode::Required)
        ->scopeTypes(['department', 'team'])
        ->entry(PanelTestOrderPermission::View)
        ->slots([new PanelSlot('write', ['retry.ms'], ['retry.ms' => 250], inheritDefaults: false)]);
    $compiler = new PanelCompiler;
    $compiler->register($recipe, $builder, app(), 'test');

    $compiledA = $compiler->compile($recipe);
    $fingerprintA = PanelFingerprint::of($compiledA, $recipe);

    expect($compiledA->label())->toBe('Config')
        ->and($compiledA->description())->toBe('Configured All')
        ->and($compiledA->prefix())->toBe('plugin-prefix')
        ->and($compiledA->tenantMode())->toBe(TenantMode::Required)
        ->and($compiledA->scopeMode())->toBe(AssignmentScopeMode::Required)
        ->and($compiledA->scopeTypes())->toBe(['department', 'team'])
        ->and($compiledA->options(PanelTestOptions::class)?->mode)->toBe('strict')
        ->and($compiledA->slot('read')->defaults['timeout.ms'])->toBe(100)
        ->and($compiledA->slot('write')->inheritDefaults)->toBeFalse()
        ->and(fn () => $compiledA->slot('missing'))->toThrow(UnknownSlotException::class)
        ->and(fn () => $builder->label('Mutate after freeze'))->toThrow(RegistryFrozenException::class)
        ->and(strlen($fingerprintA))->toBe(64);
});

it('strictly enforces precedence provider < plugin < configureAll < configure($id)', function (): void {
    PanelTestAdminProvider::$label = 'Provider Label';
    PanelTestAdminProvider::$prefix = 'provider-prefix';
    PanelTestAdminProvider::$custom = static fn (PanelBuilder $p): PanelBuilder => $p
        ->description('Provider Desc')
        ->presentation(['theme' => 'light', 'density' => 'compact'])
        ->options(new PanelTestOptions('provider-mode', 10))
        ->plugins([new PanelTestOptionsPlugin('acme/opt', 'plugin-prefix', 'plugin-mode')]);

    $registry = new PanelRegistryImpl(app());
    $registry->register(PanelTestAdminProvider::class);
    $registry->configureAll(static fn (PanelBuilder $p): PanelBuilder => $p
        ->label('ConfigureAll Label')
        ->description(null)
        ->presentation(['theme' => 'dark'])
        ->options(new PanelTestOptions('all-mode', 20)));
    $registry->configure('admin', static fn (PanelBuilder $p): PanelBuilder => $p
        ->label('ConfigureId Label')
        ->resourcePrefix('id-prefix')
        ->presentation(['density' => 'spacious']));
    $registry->freeze();

    $panel = $registry->get('admin');

    // configure($id) beats configureAll, plugin, and provider.
    expect($panel->label())->toBe('ConfigureId Label')
        ->and($panel->prefix())->toBe('id-prefix')
        ->and($panel->settings()->origin('resource_prefix'))->toBe(PanelRecipe::CONFIGURE)
        // configureAll beats plugin and provider (and non-null provider description survives null in configureAll).
        ->and($panel->options(PanelTestOptions::class)?->mode)->toBe('all-mode')
        ->and($panel->options(PanelTestOptions::class)?->ttl)->toBe(20)
        ->and($panel->description())->toBe('Provider Desc')
        ->and($panel->presentation())->toBe(['theme' => 'dark', 'density' => 'spacious']);

    // Without configure($id) or configureAll, plugin beats provider.
    $pluginWins = new PanelRegistryImpl(app());
    $pluginWins->register(PanelTestAdminProvider::class);
    $pluginWins->freeze();

    $pluginPanel = $pluginWins->get('admin');
    expect($pluginPanel->label())->toBe('Provider Label')
        ->and($pluginPanel->prefix())->toBe('plugin-prefix')
        ->and($pluginPanel->settings()->origin('resource_prefix'))->toBe('plugin:acme/opt')
        ->and($pluginPanel->options(PanelTestOptions::class)?->mode)->toBe('plugin-mode');

    // configure($id) explicitly setting null clears earlier layers.
    $cleared = new PanelRegistryImpl(app());
    $cleared->register(PanelTestAdminProvider::class);
    $cleared->configure('admin', static fn (PanelBuilder $p): PanelBuilder => $p->description(null));
    $cleared->freeze();
    expect($cleared->get('admin')->description())->toBeNull();
});

it('validates enum permission arguments and builds O(1) PanelCatalog snapshots cleanly', function (): void {
    $recipe = new PanelRecipe('admin');
    $builder = new PanelBuilder($recipe);

    expect(fn () => $builder->permissions([PanelTestNonStringEnum::class]))->toThrow(DefinitionException::class, 'string-backed enum');

    $panel = (new PanelCompiler)->compile($recipe);
    $viewDef = new PermissionDefinition('orders.view', case: PanelTestOrderPermission::View);
    $updateDef = new PermissionDefinition('orders.update', case: PanelTestOrderPermission::Update);

    $staticCatalog = PanelCatalog::build(
        panel: $panel,
        entries: [
            ['definition' => $viewDef, 'source' => 'orders', 'origin' => 'provider'],
            ['definition' => $updateDef, 'source' => 'orders', 'origin' => 'provider'],
        ],
        roles: [['key' => 'manager', 'origin' => 'provider']],
    );

    expect($staticCatalog->has('orders.view'))->toBeTrue()
        ->and($staticCatalog->keyOf(PanelTestOrderPermission::Update))->toBe('admin:orders.update')
        ->and($staticCatalog->roles())->toHaveKey('manager')
        ->and(fn () => $staticCatalog->get('missing'))->toThrow(UnknownPermissionException::class);

    $snapshot = PanelCatalog::fromSnapshot($staticCatalog->snapshot());
    expect($snapshot)->not->toBeNull()
        ->and($snapshot?->keyOf(PanelTestOrderPermission::View))->toBe('admin:orders.view');

    $merged = $staticCatalog->withDynamic([
        new PermissionDefinition('orders.export'),
    ]);
    expect($merged->has('orders.export'))->toBeTrue()
        ->and($staticCatalog->has('orders.export'))->toBeFalse();

    expect(fn () => PanelCatalog::build($panel, [
        ['definition' => $viewDef, 'source' => 'a', 'origin' => 'one'],
        ['definition' => $viewDef, 'source' => 'b', 'origin' => 'two'],
    ]))->toThrow(DuplicatePermissionException::class);

    expect(fn () => PanelCatalog::build($panel, [], [
        ['key' => 'manager', 'origin' => 'one'],
        ['key' => 'manager', 'origin' => 'two'],
    ]))->toThrow(DuplicateRoleException::class);
});
