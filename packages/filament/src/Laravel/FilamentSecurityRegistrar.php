<?php

declare(strict_types=1);

namespace Packman\Filament\Laravel;

use Filament\Actions\Action;
use Filament\Actions\Events\ActionCalling;
use Filament\Actions\Exports\Http\Controllers\DownloadExport;
use Filament\Events\ServingFilament;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Router;
use Livewire\ComponentHookRegistry;
use Livewire\LivewireManager;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Filament\Internal\Configuration\FilamentOptions;
use Packman\Filament\Internal\Surface\FilamentContext;
use Packman\Filament\Security\Authorization\AuthorizesActions;
use Packman\Filament\Security\Authorization\AuthorizesExports;
use Packman\Filament\Security\Authorization\FilamentGate;
use Packman\Filament\Security\Surface\RefusesEditableColumns;
use Packman\Filament\Security\Surface\RefusesSurfaceBoot;
use Packman\Filament\Security\Surface\RefusesSurfaceCalls;
use Packman\Filament\Security\Surface\RefusesUnverifiableDownload;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Registers Filament, Livewire, export-download, and Gate::before security hooks for `packman-filament`.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class FilamentSecurityRegistrar implements PackageRegistrar
{
    private const string PANEL_MIDDLEWARE = 'packman.panel';

    #[Override]
    public function register(Application $app): void
    {
        if (class_exists(ComponentHookRegistry::class)) {
            ComponentHookRegistry::register(RefusesSurfaceBoot::class);
        }
    }

    #[Override]
    public function boot(Application $app): void
    {
        if (class_exists(DownloadExport::class) && $app->make(FilamentOptions::class)->refuseUnverifiableDownloads) {
            $app->bindIf(DownloadExport::class, RefusesUnverifiableDownload::class);
        }

        Action::configureUsing(AuthorizesActions::configure(...));

        Table::configureUsing(static function (Table $table): void {
            if (! $table->getLivewire() instanceof ManageRelatedRecords) {
                return;
            }

            $table->modifyQueryUsing(static function (Builder $query) use ($table): Builder {
                $resource = FilamentContext::resourceOf($table->getLivewire());

                return $resource === null ? $query : FilamentGate::visible($resource, $query);
            });
        });

        $events = $app->make(Dispatcher::class);
        $events->listen(ActionCalling::class, AuthorizesActions::class);
        $events->listen(ActionCalling::class, AuthorizesExports::class);
        $events->listen(ServingFilament::class, static function (): void {
            $panel = Filament::getCurrentPanel();

            if ($panel === null || ! $panel->hasTenancy()) {
                return;
            }

            foreach ($panel->getResources() as $resource) {
                $resource::registerTenancyModelGlobalScope($panel);
            }
        });

        if ($app->bound('livewire')) {
            self::attachLivewireHooks($app->make(LivewireManager::class));
        } else {
            $app->afterResolving('livewire', static function (mixed $livewire): void {
                if ($livewire instanceof LivewireManager) {
                    self::attachLivewireHooks($livewire);
                }
            });
        }

        $app->booted(static function () use ($app): void {
            if ($app->bound('livewire')) {
                self::attachLivewireHooks($app->make(LivewireManager::class));
            }

            self::registerPersistentMiddleware($app);
            $app->make(Gate::class)->before(FilamentGate::before(...));
        });
    }

    private static function attachLivewireHooks(LivewireManager $livewire): void
    {
        /** @var array<int, true> $attached */
        static $attached = [];
        $objectId = spl_object_id($livewire);

        if (isset($attached[$objectId])) {
            return;
        }

        $attached[$objectId] = true;
        $livewire->listen('call', (new RefusesEditableColumns)(...));
        $livewire->listen('call', (new RefusesSurfaceCalls)(...));
        $livewire->listen('call', AuthorizesActions::beforeCall(...));
    }

    private static function registerPersistentMiddleware(Application $app): void
    {
        if (! $app->bound('livewire')) {
            return;
        }

        $livewire = $app->make('livewire');

        $middlewareClass = $app->make(Router::class)->getMiddleware()[self::PANEL_MIDDLEWARE] ?? null;

        if (is_string($middlewareClass)) {
            $livewire->addPersistentMiddleware($middlewareClass);
        }

        foreach ($app->make(FilamentOptions::class)->persistentMiddleware as $middleware) {
            $livewire->addPersistentMiddleware($middleware);
        }
    }
}
