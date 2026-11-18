<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Http\Controllers\DownloadExport;
use Filament\Actions\Exports\Models\Export;
use Filament\Panel as FilamentPanel;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Packman\Filament\PackmanFilamentPlugin;
use Packman\Filament\Security\Authorization\AuthorizesExports;
use Packman\Filament\Tests\Support\FilamentTestListOrders;
use Packman\Filament\Tests\Support\FilamentTestNoPolicyModel;
use Packman\Filament\Tests\Support\FilamentTestOrder;
use Packman\Filament\Tests\Support\FilamentTestOrderResource;
use Packman\Filament\Tests\Support\FilamentTestProduct;
use Packman\Filament\Tests\Support\FilamentTestProductsRelationManager;
use Packman\Filament\Tests\Support\FilamentTestReportsPage;
use Packman\Filament\Tests\Support\FilamentTestState;
use Packman\Filament\Tests\Support\FilamentTestStatsWidget;
use Packman\Filament\Tests\Support\FilamentTestUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    FilamentTestState::reset();
    $this->createFilamentTables();

    $panel = FilamentPanel::make()
        ->id('admin')
        ->default()
        ->path('admin')
        ->login()
        ->resources([FilamentTestOrderResource::class])
        ->pages([FilamentTestReportsPage::class])
        ->widgets([FilamentTestStatsWidget::class])
        ->plugin(PackmanFilamentPlugin::make()->enforce());

    $this->activatePanel($panel);

    FilamentTestUser::query()->create(['id' => 1, 'name' => 'Operator']);
    FilamentTestOrder::query()->create(['id' => 1, 'number' => 'ORD-1', 'secret' => false]);
    FilamentTestOrder::query()->create(['id' => 2, 'number' => 'ORD-2', 'secret' => true]);
    FilamentTestProduct::query()->create(['id' => 1, 'name' => 'Widget']);
    DB::table('order_product')->insert(['order_id' => 1, 'product_id' => 1]);
});

function grantAbilities(array $abilities): void
{
    foreach ($abilities as $ability) {
        Gate::define($ability, static fn (): bool => true);
    }
}

it('refuses Page boot before mount executes and Widget access when unauthorized', function (): void {
    $user = FilamentTestUser::query()->findOrFail(1);
    $this->actingAs($user);

    expect(FilamentTestReportsPage::canAccess())->toBeFalse()
        ->and(FilamentTestStatsWidget::canView())->toBeFalse();

    Livewire::test(FilamentTestReportsPage::class)->assertForbidden();
    expect(FilamentTestState::$pageMounts)->toBe(0);

    Livewire::test(FilamentTestStatsWidget::class)->assertForbidden();

    grantAbilities(['pages.reports', 'widgets.filament-test-stats-widget']);

    expect(FilamentTestReportsPage::canAccess())->toBeTrue()
        ->and(FilamentTestStatsWidget::canView())->toBeTrue();

    Livewire::test(FilamentTestReportsPage::class)->assertSuccessful();
    expect(FilamentTestState::$pageMounts)->toBe(1);
    Livewire::test(FilamentTestStatsWidget::class)->assertSuccessful();
});

it('enforces resource authorization, Gate::before denial on unowned models, and relation managers', function (): void {
    $user = FilamentTestUser::query()->findOrFail(1);
    $this->actingAs($user);

    expect(Gate::forUser($user)->allows('viewAny', FilamentTestNoPolicyModel::class))->toBeFalse();
    expect(FilamentTestOrderResource::canViewAny())->toBeFalse();

    grantAbilities(['orders.view_any', 'orders.view', 'orders.update']);
    $order = FilamentTestOrder::query()->findOrFail(1);

    expect(FilamentTestOrderResource::canViewAny())->toBeTrue()
        ->and(FilamentTestOrderResource::canView($order))->toBeTrue()
        ->and(FilamentTestOrderResource::canDelete($order))->toBeFalse()
        ->and(FilamentTestOrderResource::getEloquentQuery()->pluck('id')->all())->toBe([1])
        ->and(FilamentTestProductsRelationManager::canViewForRecord($order, FilamentTestListOrders::class))->toBeTrue();
});

it('refuses editable table columns, raw column action closures, and forged bulk keys on Livewire calls', function (): void {
    $user = FilamentTestUser::query()->findOrFail(1);
    $this->actingAs($user);
    grantAbilities(['orders.view_any', 'orders.view', 'orders.delete_any', 'orders.delete']);

    FilamentTestState::$columns = [
        TextInputColumn::make('number'),
    ];

    Livewire::test(FilamentTestListOrders::class)
        ->call('updateTableColumnState', 'number', '1', 'FORGED')
        ->assertForbidden();

    expect(FilamentTestOrder::query()->whereKey(1)->value('number'))->toBe('ORD-1');

    FilamentTestState::$columns = [
        TextColumn::make('number')->action(static function (FilamentTestOrder $record): void {
            $record->update(['number' => 'HACKED']);
        }),
    ];

    Livewire::test(FilamentTestListOrders::class)
        ->call('callTableColumnAction', 'number', '1')
        ->assertForbidden();
    expect(FilamentTestOrder::query()->whereKey(1)->value('number'))->toBe('ORD-1');

    // Custom bulk action: forged key '2' (filtered out by modifyEloquentQuery) or missing key '999' must abort 403.
    FilamentTestState::$toolbarActions = [
        BulkAction::make('purge')->action(static function (FilamentTestListOrders $livewire): void {
            FilamentTestOrder::query()->whereKey($livewire->selectedTableRecords)->delete();
        }),
    ];

    Livewire::test(FilamentTestListOrders::class)
        ->set('selectedTableRecords', ['1', '2'])
        ->call('mountAction', 'purge', [], ['table' => true, 'bulk' => true])
        ->assertForbidden();

    expect(FilamentTestOrder::query()->whereKey(1)->exists())->toBeTrue();

    // Unconfigured Action without explicit handler is denied on Widget/Resource without view permission leak.
    FilamentTestState::$recordActions = [
        Action::make('update')->action(static function (FilamentTestOrder $record): void {
            $record->update(['number' => 'UPDATED']);
        }),
    ];

    Livewire::test(FilamentTestListOrders::class)
        ->call('mountAction', 'update', [], ['table' => true, 'recordKey' => '1'])
        ->assertSuccessful();
    Livewire::test(FilamentTestListOrders::class)
        ->call('mountAction', 'update', [], ['table' => true, 'recordKey' => '1'])
        ->call('callMountedAction');
    expect(FilamentTestOrder::query()->whereKey(1)->value('number'))->toBe('ORD-1');
});

it('refuses unverifiable direct export downloads via bound DownloadExport controller', function (): void {
    $controller = app()->make(DownloadExport::class);

    expect(fn () => $controller(Request::create('/filament/exports/1/download', 'GET'), new Export))
        ->toThrow(HttpException::class);
});

it('passes the refreshed write-PDO record to the fallback policy when no explicit Gate ability exists', function (): void {
    $user = FilamentTestUser::query()->findOrFail(1);
    $this->actingAs($user);

    $observedLocked = [];
    $policy = new class($observedLocked)
    {
        /** @param list<bool> $observedLocked */
        public function __construct(private array &$observedLocked) {}

        public function update(FilamentTestUser $user, FilamentTestOrder $order): bool
        {
            $this->observedLocked[] = (bool) $order->locked;

            return ! $order->locked;
        }
    };

    Gate::policy(FilamentTestOrder::class, $policy::class);
    app()->instance($policy::class, $policy);

    $staleOrder = FilamentTestOrder::query()->findOrFail(1);
    expect($staleOrder->locked)->toBeFalse();

    // Mutate DB to locked=true while in-memory $staleOrder still has locked=false.
    FilamentTestOrder::query()->whereKey(1)->update(['locked' => true]);

    expect(FilamentTestOrderResource::canEdit($staleOrder))->toBeFalse()
        ->and($observedLocked)->toBe([true]);

    // Reverse check: DB is now unlocked (locked=false) while stale in-memory model has locked=true.
    FilamentTestOrder::query()->whereKey(1)->update(['locked' => false]);
    $staleOrder->locked = true;

    expect(FilamentTestOrderResource::canEdit($staleOrder))->toBeTrue()
        ->and($observedLocked)->toBe([true, false]);
});

it('denies exports when a second or later-chunk record fails per-row view authorization', function (): void {
    $user = FilamentTestUser::query()->findOrFail(1);
    $this->actingAs($user);

    Gate::define('orders.view_any', static fn (): bool => true);
    Gate::define('orders.view', static fn (FilamentTestUser $actor, ?FilamentTestOrder $order = null): bool => $order === null || ! $order->locked);

    // Case 1: [record 1 (allowed), record 3 (denied)] in the first chunk
    FilamentTestOrder::query()->create(['id' => 3, 'number' => 'ORD-3', 'secret' => false, 'locked' => true]);

    $component = Livewire::test(FilamentTestListOrders::class)->instance();
    $exportAction = ExportAction::make('export')->livewire($component);

    expect(static fn () => (new AuthorizesExports)($exportAction))
        ->toThrow(HttpException::class);

    // Case 2: records 1..104 allowed (chunk 1 passes completely), record 105 in chunk 2 denied
    FilamentTestOrder::query()->whereKey(3)->update(['locked' => false]);

    for ($id = 4; $id <= 104; $id++) {
        FilamentTestOrder::query()->create(['id' => $id, 'number' => "ORD-{$id}", 'secret' => false, 'locked' => false]);
    }

    FilamentTestOrder::query()->create(['id' => 105, 'number' => 'ORD-105', 'secret' => false, 'locked' => true]);

    expect(static fn () => (new AuthorizesExports)($exportAction))
        ->toThrow(HttpException::class);

    // Case 3: once record 105 is allowed too, all chunks pass export authorization
    FilamentTestOrder::query()->whereKey(105)->update(['locked' => false]);
    (new AuthorizesExports)($exportAction);
    expect(true)->toBeTrue();
});
