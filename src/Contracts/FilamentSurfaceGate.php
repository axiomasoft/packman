<?php

declare(strict_types=1);

namespace Packman\Filament\Contracts;

use Closure;
use Filament\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use UnitEnum;

/**
 * SPI deciding authorization, query visibility, unowned Gate fallbacks, and exports for Filament surfaces.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface FilamentSurfaceGate
{
    /**
     * @param  class-string<FilamentResource>  $resource
     */
    public function authorizeResource(
        string $resource,
        string|UnitEnum $action,
        ?Model $record,
        FilamentPanelPlugin $plugin,
    ): Response;

    /**
     * @param  class-string<RelationManager>  $relationManager
     * @param  (Closure(): Response)|null  $otherwise
     */
    public function authorizeRelationManager(
        string $relationManager,
        string|UnitEnum $action,
        ?Model $record,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response;

    /**
     * @param  class-string<Page>  $page
     * @param  (Closure(): Response)|null  $otherwise
     */
    public function authorizePage(
        string $page,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response;

    /**
     * @param  class-string<Widget>  $widget
     * @param  (Closure(): Response)|null  $otherwise
     */
    public function authorizeWidget(
        string $widget,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response;

    /**
     * @template TModel of Model
     *
     * @param  class-string<FilamentResource>  $resource
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeVisibleQuery(
        string $resource,
        Builder $query,
        FilamentPanelPlugin $plugin,
    ): Builder;

    /**
     * @param  array<mixed>  $arguments
     */
    public function beforeGate(
        ?object $user,
        string $ability,
        array $arguments,
        FilamentPanelPlugin $plugin,
    ): ?bool;

    /**
     * Resolves a custom owner-resource ability declared on a relation manager or related-records page, or null to use the default.
     *
     * @param  class-string  $relationManager
     */
    public function resolveRelationAbility(
        string $relationManager,
        FilamentPanelPlugin $plugin,
    ): ?string;

    /**
     * Verifies panel-level export prerequisites (such as primary-read mode) in enforced panels.
     */
    public function canExport(FilamentPanelPlugin $plugin): bool;
}
