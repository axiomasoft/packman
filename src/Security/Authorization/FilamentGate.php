<?php

declare(strict_types=1);

namespace Packman\Filament\Security\Authorization;

use Closure;
use Filament\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Filament\Enums\FilamentErrorCode;
use Packman\Filament\Internal\Surface\FilamentContext;
use UnitEnum;

use function Filament\get_authorization_response;

/**
 * Facade delegating Filament surface, visibility, and Gate::before checks through the bound `FilamentSurfaceGate`.
 */
#[Api(since: '1.0')]
final class FilamentGate
{
    /**
     * @param  class-string<FilamentResource>  $resource
     */
    public static function resource(string $resource, string|UnitEnum $action, ?Model $record = null): Response
    {
        $context = FilamentContext::current();

        if ($context === null) {
            if ($resource::shouldSkipAuthorization()) {
                return Response::allow();
            }

            return get_authorization_response($action, $record ?? $resource::getModel(), $resource::shouldCheckPolicyExistence());
        }

        return resolve(FilamentSurfaceGate::class)->authorizeResource($resource, $action, $record, $context->plugin);
    }

    /**
     * @param  class-string<RelationManager>  $relationManager
     * @param  (Closure(): Response)|null  $otherwise
     */
    public static function relationManager(string $relationManager, string|UnitEnum $action, ?Model $record = null, ?Closure $otherwise = null): Response
    {
        $context = FilamentContext::current();

        if ($context === null) {
            $resource = FilamentContext::relationResource($relationManager);

            if ($resource !== null) {
                return self::resource($resource, $action, $record);
            }

            return ($otherwise ?? Response::allow(...))();
        }

        return resolve(FilamentSurfaceGate::class)->authorizeRelationManager($relationManager, $action, $record, $context->plugin, $otherwise);
    }

    /**
     * @param  class-string<Page>  $page
     * @param  (Closure(): Response)|null  $otherwise
     */
    public static function page(string $page, ?Closure $otherwise = null): Response
    {
        $fallback = $otherwise ?? Response::allow(...);
        $context = FilamentContext::current();

        if ($context === null) {
            return $fallback();
        }

        return resolve(FilamentSurfaceGate::class)->authorizePage($page, $context->plugin, $fallback);
    }

    /**
     * @param  class-string<Widget>  $widget
     * @param  (Closure(): Response)|null  $otherwise
     */
    public static function widget(string $widget, ?Closure $otherwise = null): Response
    {
        $fallback = $otherwise ?? Response::allow(...);
        $context = FilamentContext::current();

        if ($context === null) {
            return $fallback();
        }

        return resolve(FilamentSurfaceGate::class)->authorizeWidget($widget, $context->plugin, $fallback);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<FilamentResource>  $resource
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function visible(string $resource, Builder $query): Builder
    {
        $context = FilamentContext::current();

        if ($context === null) {
            return $query;
        }

        return resolve(FilamentSurfaceGate::class)->scopeVisibleQuery($resource, $query, $context->plugin);
    }

    /**
     * Refuses unowned Filament fallback checks in enforced panels via `Gate::before`.
     *
     * @param  array<mixed>  $arguments
     */
    public static function before(?object $user, string $ability, array $arguments = []): ?bool
    {
        $context = FilamentContext::serving();

        if ($context === null) {
            return null;
        }

        return resolve(FilamentSurfaceGate::class)->beforeGate($user, $ability, $arguments, $context->plugin);
    }

    public static function refused(?FilamentErrorCode $code = null): Response
    {
        return Response::deny(code: ($code ?? FilamentErrorCode::AuthorizationDenied)->value())->withStatus(403);
    }
}
