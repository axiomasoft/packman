<?php

declare(strict_types=1);

namespace Packman\Filament\Concerns\Authorization;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Filament\Internal\Surface\FilamentContext;
use Packman\Filament\Security\Authorization\FilamentGate;

/**
 * Delegates Filament relation-manager authorization and table query filtering to its target resource.
 *
 * @phpstan-require-extends RelationManager
 */
#[Api(since: '1.0')]
trait AuthorizesRelationManager
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return FilamentGate::relationManager(
            static::class,
            'view_any',
            otherwise: static fn (): Response => parent::canViewForRecord($ownerRecord, $pageClass)
                ? Response::allow()
                : Response::deny(),
        )->allowed();
    }

    public function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        return FilamentGate::relationManager(
            static::class,
            $action,
            $record,
            fn (): Response => parent::getAuthorizationResponse($action, $record),
        );
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()->modifyQueryUsing(static function (Builder $query): Builder {
            $resource = FilamentContext::relationResource(static::class);

            return $resource === null ? $query : FilamentGate::visible($resource, $query);
        });
    }
}
