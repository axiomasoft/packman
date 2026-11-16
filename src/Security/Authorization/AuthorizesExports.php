<?php

declare(strict_types=1);

namespace Packman\Filament\Security\Authorization;

use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Facades\Filament;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;
use Packman\Filament\Internal\Surface\FilamentContext;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Enums\Panel\Reads;
use Throwable;

/**
 * Verifies primary reads, fresh user state, and per-row view authorization before an export starts in an enforced panel.
 */
#[Internal]
final class AuthorizesExports
{
    public function __invoke(object $action): void
    {
        if (! $action instanceof ExportAction && ! $action instanceof ExportBulkAction) {
            return;
        }

        $context = FilamentContext::serving();

        if ($context === null || ! $context->enforced()) {
            return;
        }

        $resource = FilamentContext::resourceOf($action->getLivewire());

        if ($resource === null) {
            abort(403);
        }

        $livewire = $action->getLivewire();
        $authorized = false;

        try {
            $guardPanelId = $context->guardPanel();

            if ($guardPanelId !== null && app()->bound(PanelRegistry::class)) {
                $panel = resolve(PanelRegistry::class)->find($guardPanelId);

                if ($panel === null || $panel->settings()->reads() !== Reads::Primary) {
                    abort(403);
                }
            }

            $user = Filament::auth()->user();
            $fresh = null;

            if ($user instanceof Model) {
                $userQuery = $user->newQuery();
                $userQuery->getQuery()->useWritePdo();
                $fresh = $userQuery->whereKey($user->getKey())->first();
            }

            if ($fresh instanceof Model && $livewire instanceof HasTable) {
                Filament::auth()->setUser($fresh);
                $query = $action instanceof ExportBulkAction
                    ? $livewire->getSelectedTableRecordsQuery()
                    : $livewire->getTableQueryForExport();
                $query->getQuery()->useWritePdo();

                if ($query->getModel()::class === $resource::getModel()) {
                    (clone $query)->each(static function (Model $record) use ($resource, &$authorized): bool {
                        $authorized = FilamentGate::resource($resource, 'view', $record)->allowed();

                        return ! $authorized;
                    }, 100);
                }
            }
        } catch (Throwable) {
            $authorized = false;
        }

        abort_unless($authorized, 403);
    }
}
