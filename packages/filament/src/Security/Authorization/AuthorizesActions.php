<?php

declare(strict_types=1);

namespace Packman\Filament\Security\Authorization;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\AssociateAction;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Filament\Tables\Contracts\HasTable;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Livewire\ImplicitlyBoundMethod;
use Packman\Attributes\Internal;
use Packman\Filament\Internal\Surface\FilamentContext;
use ReflectionFunction;
use ReflectionProperty;
use Throwable;

/**
 * Default authorization for Filament actions and fresh write-PDO verification for bulk selections.
 */
#[Internal]
final class AuthorizesActions
{
    /** @var list<string> */
    private const array EARLY_BULK_HOOKS = ['beforeFormFilled', 'afterFormFilled', 'beforeFormValidated', 'afterFormValidated', 'mountUsing'];

    public static function configure(Action $action): void
    {
        $action->authorize(static function (Action $action): Response {
            $livewire = $action->getHasActionsLivewire();
            $otherwise = $livewire?->getDefaultActionAuthorizationResponse($action);
            $context = FilamentContext::serving();
            $resource = FilamentContext::resourceOf($livewire);

            if ($context?->enforced() !== true || ($resource !== null && $context->excludes('resources', $resource))) {
                return $otherwise ?? Response::allow();
            }

            if ($resource === null) {
                if ($livewire instanceof Widget && $context->excludes('widgets', $livewire::class)) {
                    return $otherwise ?? Response::allow();
                }

                return $otherwise ?? Response::deny();
            }

            if (self::changesRelation($action) || (self::writesOwnerRow($action) && FilamentContext::ownerOf($livewire) !== null)) {
                $owner = self::relation($action, $livewire, $resource);

                if (! $owner->allowed()) {
                    return $owner;
                }

                return ($otherwise !== null && ! $otherwise->allowed()) ? $otherwise : ($otherwise ?? $owner);
            }

            $record = $action->getRecord();
            $ability = self::ability($action);
            $bulkQuery = $action instanceof BulkAction && $livewire instanceof HasTable && $livewire->getTable()->hasQuery();

            $decision = FilamentGate::resource(
                $resource,
                $bulkQuery && ! str_ends_with($ability, '_any') ? $ability.'_any' : $ability,
                $record instanceof Model && ! self::exports($action) ? $record : null,
            );

            if (! $decision->allowed()) {
                return $decision;
            }

            return ($otherwise !== null && ! $otherwise->allowed()) ? $otherwise : $decision;
        });

        if (! $action instanceof BulkAction) {
            return;
        }

        $action->authorizeIndividualRecords(static function (Model $record) use ($action): Response {
            $livewire = $action->getLivewire();
            $context = FilamentContext::serving();
            $resource = FilamentContext::resourceOf($livewire);

            if ($context?->enforced() !== true || ($resource !== null && $context->excludes('resources', $resource))) {
                $filament = $livewire instanceof HasActions ? $livewire->getDefaultActionIndividualRecordAuthorizationResponseResolver($action) : null;
                $response = $filament === null ? null : $filament($record);

                return $response instanceof Response ? $response : Response::allow();
            }

            if ($resource !== null && self::changesRelation($action)) {
                return self::relation($action, $livewire, $resource);
            }

            if ($resource === null && $livewire instanceof Widget && $context->excludes('widgets', $livewire::class)) {
                return Response::allow();
            }

            return $resource === null
                ? Response::deny()
                : FilamentGate::resource($resource, self::exports($action) ? 'view' : self::ability($action), $record);
        });
    }

    public function __invoke(object $action): void
    {
        if (! $action instanceof Action) {
            return;
        }

        $context = FilamentContext::serving();
        $resource = FilamentContext::resourceOf($action->getLivewire());

        if ($context?->enforced() !== true || $resource === null || $context->excludes('resources', $resource)) {
            return;
        }

        abort_unless($action->getAuthorizationResponse()->allowed(), 403);

        if (! $action instanceof BulkAction) {
            return;
        }

        $livewire = $action->getLivewire();

        if (! $livewire instanceof HasTable || ! $livewire instanceof HasActions || ! $livewire->getTable()->hasQuery()) {
            return;
        }

        abort_unless($action->shouldAuthorizeIndividualRecords(), 403);

        if ($action instanceof ExportBulkAction) {
            $action->fetchSelectedRecords();
        }

        if ($livewire->getDefaultActionIndividualRecordAuthorizationResponseResolver($action) !== null
            && self::usesFilteredBulkSelection($action)) {
            $action->fetchSelectedRecords();

            return;
        }

        $tracking = property_exists($livewire, 'isTrackingDeselectedTableRecords')
            && new ReflectionProperty($livewire, 'isTrackingDeselectedTableRecords')->getValue($livewire) === true;

        $selected = self::keys(
            property_exists($livewire, 'selectedTableRecords')
                ? new ReflectionProperty($livewire, 'selectedTableRecords')->getValue($livewire)
                : null,
        );

        self::keys(
            property_exists($livewire, 'deselectedTableRecords')
                ? new ReflectionProperty($livewire, 'deselectedTableRecords')->getValue($livewire)
                : null,
        );

        abort_if($tracking && $selected !== [], 403);

        $resolved = [];
        $selectionQuery = $livewire->getSelectedTableRecordsQuery();
        $selectionQuery->getQuery()->useWritePdo();
        $selectionQuery->each(function (Model $record) use ($action, $livewire, &$resolved): void {
            abort_unless($action->getIndividualRecordAuthorizationResponse($record)->allowed(), 403);
            $resolved[] = $livewire->getTableRecordKey($record);
        }, 100);

        if (! $tracking) {
            abort_unless(array_diff($selected, $resolved) === [], 403);
        }
    }

    /**
     * Checks early bulk lifecycle hooks before ActionCalling fires while the explicit selection is intact.
     *
     * @param  array<mixed>  $params
     */
    public static function beforeCall(object $component, string $method, array $params): void
    {
        if (! in_array($method, ['mountAction', 'callMountedAction'], true)
            || (! $component instanceof ResourcePage && ! $component instanceof RelationManager)) {
            return;
        }

        $context = FilamentContext::serving();
        $resource = FilamentContext::resourceOf($component);

        if ($context?->enforced() !== true || $resource === null || $context->excludes('resources', $resource)) {
            return;
        }

        if ($method === 'mountAction') {
            try {
                $resolved = ImplicitlyBoundMethod::resolveMethodDependencies(app(), [$component, $method], $params);
            } catch (Throwable) {
                abort(403);
            }

            $bound = is_array($resolved) && is_array($resolved['named'] ?? null) ? $resolved['named'] : [];
            $name = $bound['name'] ?? null;
            $arguments = $bound['arguments'] ?? [];
            $actionContext = $bound['context'] ?? [];
            abort_unless(is_string($name) && is_array($arguments) && is_array($actionContext), 403);

            $mounted = property_exists($component, 'mountedActions') ? $component->mountedActions : [];

            try {
                $action = $component->getAction([
                    ...(is_array($mounted) ? $mounted : []),
                    ['name' => $name, 'arguments' => $arguments, 'context' => $actionContext],
                ], isMounting: false);
            } catch (ActionNotResolvableException) {
                return;
            }
        } else {
            $action = $component->getMountedAction();
        }

        if ($action instanceof BulkAction && self::hasHooks($action, self::EARLY_BULK_HOOKS)) {
            (new self)($action);
        }
    }

    /**
     * @return list<string>
     */
    private static function keys(mixed $keys): array
    {
        abort_unless(is_array($keys), 403);

        $validated = [];

        foreach ($keys as $key) {
            abort_unless(is_string($key) || is_int($key), 403);
            $validated[] = (string) $key;
        }

        return array_values(array_unique($validated));
    }

    private static function usesFilteredBulkSelection(BulkAction $action): bool
    {
        $callback = $action->getActionFunction();

        if (! $callback instanceof Closure) {
            return false;
        }

        $scope = new ReflectionFunction($callback)->getClosureScopeClass()?->getName();

        if (! in_array($scope, [
            DeleteBulkAction::class,
            ForceDeleteBulkAction::class,
            RestoreBulkAction::class,
        ], true)) {
            return false;
        }

        return ! self::hasHooks($action, ['using', 'before', 'after', ...self::EARLY_BULK_HOOKS]);
    }

    /**
     * @param  list<string>  $hooks
     */
    private static function hasHooks(Action $action, array $hooks): bool
    {
        return array_any($hooks, fn (string $hook): bool => new ReflectionProperty($action, $hook)->getValue($action) !== null);
    }

    private static function writesOwnerRow(Action $action): bool
    {
        return $action instanceof CreateAction || $action instanceof ReplicateAction;
    }

    private static function changesRelation(Action $action): bool
    {
        return $action instanceof AttachAction
            || $action instanceof AssociateAction
            || $action instanceof DetachAction
            || $action instanceof DetachBulkAction
            || $action instanceof DissociateAction
            || $action instanceof DissociateBulkAction;
    }

    /**
     * @param  class-string<FilamentResource>  $related
     */
    private static function relation(Action $action, mixed $livewire, string $related): Response
    {
        $owner = FilamentContext::ownerOf($livewire);

        if ($owner === null || ! is_object($livewire)) {
            return Response::deny();
        }

        $ownerQuery = $owner[1]->newQuery();
        $ownerQuery->getQuery()->useWritePdo();
        $record = $ownerQuery->whereKey($owner[1]->getKey())->first();

        if (! $record instanceof Model || $record::class !== $owner[0]::getModel()) {
            return Response::deny();
        }

        $response = FilamentGate::resource($owner[0], FilamentGate::relationAbility($livewire::class), $record);

        if (! $response->allowed() || (! $action instanceof AttachAction && ! $action instanceof AssociateAction)) {
            return $response;
        }

        return FilamentGate::resource($related, 'view_any');
    }

    private static function ability(Action $action): string
    {
        if (self::exports($action)) {
            return 'view_any';
        }

        $name = FilamentContext::ability($action->getName() ?? 'unnamed');

        return match ($name) {
            'edit' => 'update',
            'revoke' => 'delete',
            'why', 'why_grant' => 'view',
            default => $name,
        };
    }

    private static function exports(Action $action): bool
    {
        return $action instanceof ExportAction || $action instanceof ExportBulkAction;
    }
}
