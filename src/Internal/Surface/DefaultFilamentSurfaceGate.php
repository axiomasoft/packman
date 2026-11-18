<?php

declare(strict_types=1);

namespace Packman\Filament\Internal\Surface;

use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Override;
use Packman\Attributes\Internal;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ScopeRef;
use Packman\Context\Exceptions\ScopeUnavailableException;
use Packman\Filament\Contracts\FilamentPanelPlugin;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Filament\Enums\FilamentErrorCode;
use Packman\Filament\Internal\Reflection\ClassTraits;
use Packman\Panels\Contracts\Admission\PanelAdmitter;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Enums\Admission\TenantMode;
use Packman\Panels\Enums\Panel\Reads;
use Throwable;
use UnitEnum;

use function Filament\get_authorization_response;

/**
 * Default Filament surface authorization gate backed by Laravel Gate/Policies and optional PanelRegistry/Admitter.
 */
#[Internal]
final readonly class DefaultFilamentSurfaceGate implements FilamentSurfaceGate
{
    public function __construct(
        private Gate $gate,
        private ?PanelRegistry $panels = null,
        private ?PanelAdmitter $admission = null,
        private ?ContextResolverInterface $context = null,
        private ?Container $container = null,
    ) {}

    /**
     * @param  class-string<FilamentResource>  $resource
     */
    #[Override]
    public function authorizeResource(
        string $resource,
        string|UnitEnum $action,
        ?Model $record,
        FilamentPanelPlugin $plugin,
    ): Response {
        $context = FilamentContext::current();

        if ($context === null || $context->excludes('resources', $resource)) {
            return self::fallbackResponse($resource, $action, $record);
        }

        $ability = FilamentContext::ability($action);

        if ($plugin->isEnforced()) {
            $user = Filament::auth()->user();

            if ($user === null) {
                return self::refused(FilamentErrorCode::AuthorizationDenied);
            }

            if (! $this->guardPanelAllows($plugin, $ability, $user)) {
                return self::refused(FilamentErrorCode::AuthorizationDenied);
            }

            if ($record !== null) {
                $record = $this->freshRecord($resource, $record, $ability);

                if (! $record instanceof Model) {
                    return self::refused(FilamentErrorCode::AuthorizationDenied);
                }
            }
        }

        $key = $context->resourceKey($resource, $action);

        if ($this->gate->has($key)) {
            $user = Filament::auth()->user();

            if ($user === null) {
                return self::refused(FilamentErrorCode::AuthorizationDenied);
            }

            return $this->gate->forUser($user)->inspect($key, $record !== null ? [$record] : []);
        }

        return self::fallbackResponse($resource, $action, $record);
    }

    /**
     * @param  class-string<RelationManager>  $relationManager
     * @param  (Closure(): Response)|null  $otherwise
     */
    #[Override]
    public function authorizeRelationManager(
        string $relationManager,
        string|UnitEnum $action,
        ?Model $record,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response {
        $resource = FilamentContext::relationResource($relationManager);

        if ($resource !== null) {
            return $this->authorizeResource($resource, $action, $record, $plugin);
        }

        if ($plugin->isEnforced()) {
            return self::refused(FilamentErrorCode::AuthorizationDenied);
        }

        return ($otherwise ?? Response::allow(...))();
    }

    /**
     * @param  class-string<Page>  $page
     * @param  (Closure(): Response)|null  $otherwise
     */
    #[Override]
    public function authorizePage(
        string $page,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response {
        $fallback = $otherwise ?? Response::allow(...);
        $context = FilamentContext::current();

        if ($context === null || $context->excludes('pages', $page)) {
            return $fallback();
        }

        $user = Filament::auth()->user();

        if ($plugin->isEnforced() && ($user === null || ! $this->guardPanelAllows($plugin, 'view', $user))) {
            return self::refused(FilamentErrorCode::AuthorizationDenied);
        }

        $key = $context->pageKey($page);

        if ($this->gate->has($key)) {
            return $user === null
                ? self::refused(FilamentErrorCode::AuthorizationDenied)
                : $this->gate->forUser($user)->inspect($key);
        }

        return $plugin->isEnforced() ? self::refused(FilamentErrorCode::AuthorizationDenied) : $fallback();
    }

    /**
     * @param  class-string<Widget>  $widget
     * @param  (Closure(): Response)|null  $otherwise
     */
    #[Override]
    public function authorizeWidget(
        string $widget,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response {
        $fallback = $otherwise ?? Response::allow(...);
        $context = FilamentContext::current();

        if ($context === null || $context->excludes('widgets', $widget)) {
            return $fallback();
        }

        $user = Filament::auth()->user();

        if ($plugin->isEnforced() && ($user === null || ! $this->guardPanelAllows($plugin, 'view', $user))) {
            return self::refused(FilamentErrorCode::AuthorizationDenied);
        }

        $key = $context->widgetKey($widget);

        if ($this->gate->has($key)) {
            return $user === null
                ? self::refused(FilamentErrorCode::AuthorizationDenied)
                : $this->gate->forUser($user)->inspect($key);
        }

        return $plugin->isEnforced() ? self::refused(FilamentErrorCode::AuthorizationDenied) : $fallback();
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<FilamentResource>  $resource
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    #[Override]
    public function scopeVisibleQuery(
        string $resource,
        Builder $query,
        FilamentPanelPlugin $plugin,
    ): Builder {
        $context = FilamentContext::current();

        if ($context === null || ! $plugin->isEnforced() || $context->excludes('resources', $resource)) {
            return $query;
        }

        $user = Filament::auth()->user();

        if ($user === null || ! $this->guardPanelAllows($plugin, 'view', $user)) {
            return $query->whereKey([]);
        }

        $viewKey = $context->resourceKey($resource, 'view');

        if ($this->gate->has($viewKey) && $this->gate->forUser($user)->denies($viewKey)) {
            return $query->whereKey([]);
        }

        return $query;
    }

    /**
     * @param  array<mixed>  $arguments
     */
    #[Override]
    public function beforeGate(
        ?object $user,
        string $ability,
        array $arguments,
        FilamentPanelPlugin $plugin,
    ): ?bool {
        if (! $plugin->isEnforced() || ! in_array(FilamentContext::ability($ability), $plugin->getAbilities(), true)) {
            return null;
        }

        $model = $arguments[0] ?? null;
        $model = match (true) {
            $model instanceof Model => $model::class,
            is_string($model) && is_subclass_of($model, Model::class) => $model,
            default => null,
        };

        if ($model === null || $this->gate->has($ability)) {
            return null;
        }

        $policy = $this->gate->getPolicyFor($model);

        if (! is_object($policy)) {
            return false;
        }

        $method = str_contains($ability, '-') ? Str::camel($ability) : $ability;

        return method_exists($policy, $method) ? null : false;
    }

    /**
     * @param  class-string  $relationManager
     */
    #[Override]
    public function resolveRelationAbility(
        string $relationManager,
        FilamentPanelPlugin $plugin,
    ): ?string {
        return null;
    }

    #[Override]
    public function canExport(FilamentPanelPlugin $plugin): bool
    {
        $guardPanelId = $plugin->getGuardPanel();

        if ($guardPanelId === null) {
            return true;
        }

        if ($this->panels === null) {
            return false;
        }

        try {
            $panel = $this->panels->find($guardPanelId);
        } catch (Throwable) {
            return false;
        }

        return $panel !== null && $panel->settings()->reads() === Reads::Primary;
    }

    private function guardPanelAllows(FilamentPanelPlugin $plugin, string $ability, object $user): bool
    {
        $guardPanelId = $plugin->getGuardPanel();

        if ($guardPanelId === null) {
            return true;
        }

        if ($this->panels === null) {
            return false;
        }

        try {
            $panel = $this->panels->find($guardPanelId);
        } catch (Throwable) {
            return false;
        }

        if ($panel === null) {
            return false;
        }

        if (! in_array($ability, ['view', 'view_any'], true) && $panel->settings()->reads() !== Reads::Primary) {
            return false;
        }

        $entryPermission = $panel->entry();

        if ($entryPermission !== null) {
            $key = match (true) {
                is_string($entryPermission) => $entryPermission,
                $entryPermission instanceof BackedEnum => (string) $entryPermission->value,
                default => $entryPermission->name,
            };

            if ($this->gate->has($key) && $this->gate->forUser($user)->denies($key)) {
                return false;
            }
        }

        $requiresAdmission = $panel->subjectModels() !== [] || $panel->tenantMode() === TenantMode::Required;

        if (! $requiresAdmission) {
            return true;
        }

        if ($this->admission === null) {
            return false;
        }

        $scope = $this->resolveScope();

        if ($panel->tenantMode() === TenantMode::Required && in_array($scope->id(), ['global', 'system', ''], true)) {
            return false;
        }

        return $this->admission->admit($panel, $user, $scope) !== null;
    }

    private function resolveScope(): ContextScopeInterface
    {
        $resolver = $this->context;

        if ($resolver === null && $this->container !== null && $this->container->bound(ContextResolverInterface::class)) {
            $resolver = $this->container->make(ContextResolverInterface::class);
        }

        if ($resolver === null) {
            return new ScopeRef('global');
        }

        try {
            return $resolver->current() ?? new ScopeRef('global');
        } catch (ScopeUnavailableException) {
            return new ScopeRef('global');
        }
    }

    /**
     * @param  class-string<FilamentResource>  $resource
     */
    private static function fallbackResponse(string $resource, string|UnitEnum $action, ?Model $record): Response
    {
        if ($resource::shouldSkipAuthorization()) {
            return Response::allow();
        }

        return get_authorization_response($action, $record ?? $resource::getModel(), $resource::shouldCheckPolicyExistence());
    }

    /**
     * @param  class-string<FilamentResource>  $resource
     */
    private function freshRecord(string $resource, Model $record, string $ability): ?Model
    {
        try {
            if (! is_a($record, $resource::getModel(), true) || $record->getKey() === null) {
                return null;
            }

            $query = $record->newQuery();
            $query->getQuery()->useWritePdo();

            if (ClassTraits::has($record, SoftDeletes::class) && in_array($ability, ['restore', 'force_delete'], true)) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            return $query->whereKey($record->getKey())->first();
        } catch (Throwable) {
            return null;
        }
    }

    private static function refused(FilamentErrorCode $code): Response
    {
        return Response::deny(code: $code->value())->withStatus(403);
    }
}
