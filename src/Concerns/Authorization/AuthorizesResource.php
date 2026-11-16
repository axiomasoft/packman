<?php

declare(strict_types=1);

namespace Packman\Filament\Concerns\Authorization;

use Filament\Resources\Resource as FilamentResource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Filament\Security\Authorization\FilamentGate;
use UnitEnum;

/**
 * Delegates Filament resource authorization and query visibility filtering through `FilamentGate`.
 *
 * Customize queries by overriding `modifyEloquentQuery()` instead of `getEloquentQuery()`.
 *
 * @phpstan-require-extends FilamentResource
 */
#[Api(since: '1.0')]
trait AuthorizesResource
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return FilamentGate::resource(static::class, $action, $record);
    }

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return FilamentGate::visible(static::class, static::modifyEloquentQuery(parent::getEloquentQuery()));
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected static function modifyEloquentQuery(Builder $query): Builder
    {
        return $query;
    }
}
