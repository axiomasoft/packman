<?php

declare(strict_types=1);

namespace Packman\Filament;

use Closure;
use Filament\Actions\AssociateAction;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel as FilamentPanel;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Filament\Concerns\Authorization\AuthorizesPage;
use Packman\Filament\Concerns\Authorization\AuthorizesRelationManager;
use Packman\Filament\Concerns\Authorization\AuthorizesResource;
use Packman\Filament\Concerns\Authorization\AuthorizesWidget;
use Packman\Filament\Contracts\FilamentPanelPlugin;
use Packman\Filament\Exceptions\Binding\InvalidBindingStateException;
use Packman\Filament\Internal\Configuration\FilamentOptions;
use Packman\Filament\Internal\Reflection\ClassTraits;
use Packman\Filament\Internal\Surface\FilamentContext;
use Packman\Filament\Security\Authorization\FilamentGate;
use Packman\Panels\Contracts\Registry\PanelRegistry;

/**
 * Connects a Filament panel to Packman surface enforcement, admission middleware, and toggleable resources.
 */
#[Api(since: '1.0')]
final class PackmanFilamentPlugin implements FilamentPanelPlugin
{
    public const string ID = 'packman';

    private const string PANEL_MIDDLEWARE = 'packman.panel';

    private ?string $guardPanel;

    private bool $enforce;

    /** @var list<string> */
    private array $abilities;

    /** @var array{resources: list<string>, pages: list<string>, widgets: list<string>} */
    private array $exclude;

    /** @var list<string> */
    private array $persistentMiddleware;

    /** @var array<string, array{class: class-string, feature: bool|Closure(): bool}> */
    private array $candidateResources = [];

    /** @var array<string, bool> */
    private array $resourceOverrides = [];

    private ?bool $middlewareWithTenancy = null;

    #[Internal('Constructed by the package registrar.')]
    public function __construct(private readonly FilamentOptions $options)
    {
        $this->guardPanel = $options->guardPanel;
        $this->enforce = $options->enforce;
        $this->abilities = $options->abilities;
        $this->exclude = $options->exclude;
        $this->persistentMiddleware = $options->persistentMiddleware;
    }

    public static function make(): static
    {
        return resolve(self::class);
    }

    /**
     * Localized typed accessor for reading this plugin from a Filament panel instance.
     *
     * @throws InvalidBindingStateException
     */
    public static function current(FilamentPanel $panel): self
    {
        if (! $panel->hasPlugin(self::ID)) {
            throw InvalidBindingStateException::invalidConfiguration('Filament panel "'.$panel->getId().'" does not have PackmanFilamentPlugin registered.');
        }

        $plugin = $panel->getPlugin(self::ID);

        return $plugin instanceof self
            ? $plugin
            : throw InvalidBindingStateException::invalidConfiguration('Plugin "'.self::ID.'" on panel "'.$panel->getId().'" must implement '.self::class.'.');
    }

    /**
     * @throws InvalidBindingStateException
     */
    public static function get(?string $filamentPanel = null): self
    {
        $panel = $filamentPanel === null ? Filament::getCurrentOrDefaultPanel() : Filament::getPanel($filamentPanel);

        if (! $panel instanceof FilamentPanel) {
            throw InvalidBindingStateException::invalidConfiguration('Filament panel "'.($filamentPanel ?? 'default').'" could not be resolved.');
        }

        return self::current($panel);
    }

    #[Override]
    public function getId(): string
    {
        return self::ID;
    }

    public function guardPanel(?string $panel): static
    {
        $this->guardPanel = $panel;

        return $this;
    }

    public function enforce(bool $enforce = true): static
    {
        $this->enforce = $enforce;

        return $this;
    }

    /**
     * @param  list<string>  $abilities
     */
    public function abilities(array $abilities): static
    {
        foreach ($abilities as $ability) {
            if (preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/', $ability) !== 1) {
                throw InvalidBindingStateException::invalidConfiguration('The ability "'.$ability.'" must be snake_case.');
            }
        }

        $this->abilities = array_values(array_unique($abilities));

        return $this;
    }

    /**
     * @param  list<class-string>  $resources
     * @param  list<class-string>  $pages
     * @param  list<class-string>  $widgets
     */
    public function exclude(array $resources = [], array $pages = [], array $widgets = []): static
    {
        $this->exclude = [
            'resources' => array_values(array_unique($resources)),
            'pages' => array_values(array_unique($pages)),
            'widgets' => array_values(array_unique($widgets)),
        ];

        return $this;
    }

    /**
     * @param  list<string>  $middleware
     */
    public function persistentMiddleware(array $middleware): static
    {
        $this->persistentMiddleware = array_values(array_unique($middleware));

        return $this;
    }

    /**
     * Registers toggleable resource candidates gated by both `packman-filament.resources.<key>` and an optional subsystem flag.
     *
     * @param  class-string  $resourceClass
     * @param  bool|(Closure(): bool)  $featureEnabled
     */
    public function resource(string $key, string $resourceClass, bool|Closure $featureEnabled = true): static
    {
        $this->candidateResources[$key] = [
            'class' => $resourceClass,
            'feature' => $featureEnabled,
        ];

        return $this;
    }

    public function enableResource(string $key, bool $enabled = true): static
    {
        $this->resourceOverrides[$key] = $enabled;

        return $this;
    }

    /**
     * @return list<class-string>
     */
    public function enabledResources(): array
    {
        $enabled = [];

        foreach ($this->candidateResources as $key => $candidate) {
            $configEnabled = $this->resourceOverrides[$key] ?? $this->options->resourceEnabled($key);

            if (! $configEnabled) {
                continue;
            }

            $feature = $candidate['feature'];
            $featureEnabled = $feature instanceof Closure ? $feature() : $feature;

            if ($featureEnabled) {
                $enabled[] = $candidate['class'];
            }
        }

        return $enabled;
    }

    #[Override]
    public function getGuardPanel(): ?string
    {
        return $this->guardPanel;
    }

    #[Override]
    public function isEnforced(): bool
    {
        return $this->enforce;
    }

    /**
     * @return array{resources: list<string>, pages: list<string>, widgets: list<string>}
     */
    #[Override]
    public function getExclude(): array
    {
        return $this->exclude;
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function getAbilities(): array
    {
        return $this->abilities;
    }

    /**
     * @return list<string>
     */
    public function getPersistentMiddleware(): array
    {
        return $this->persistentMiddleware;
    }

    #[Override]
    public function register(FilamentPanel $panel): void
    {
        $this->middlewareWithTenancy = $panel->hasTenancy();
        $middleware = $this->persistentMiddleware;

        if ($this->guardPanel !== null && resolve('router')->getMiddleware() !== [] && array_key_exists(self::PANEL_MIDDLEWARE, resolve('router')->getMiddleware())) {
            $middleware[] = self::PANEL_MIDDLEWARE.':'.$this->guardPanel;
        }

        if ($middleware !== []) {
            $middleware = array_values(array_unique($middleware));

            if ($this->middlewareWithTenancy) {
                $panel->tenantMiddleware($middleware);
            } else {
                $panel->authMiddleware($middleware);
            }
        }

        $resources = $this->enabledResources();

        if ($resources !== []) {
            $panel->resources($resources);
        }
    }

    #[Override]
    public function boot(FilamentPanel $panel): void
    {
        if ($this->middlewareWithTenancy !== $panel->hasTenancy()) {
            throw InvalidBindingStateException::invalidConfiguration(
                'Filament panel "'.$panel->getId().'" configured tenancy after PackmanFilamentPlugin was registered: call ->tenant(...) before ->plugin(PackmanFilamentPlugin::make()).',
            );
        }

        if ($this->guardPanel !== null && app()->bound(PanelRegistry::class)) {
            $registry = resolve(PanelRegistry::class);

            if ($registry->isFrozen() && $registry->find($this->guardPanel) === null) {
                throw InvalidBindingStateException::invalidConfiguration(
                    'Filament panel "'.$panel->getId().'" references unknown guard panel "'.$this->guardPanel.'".',
                );
            }
        }

        if ($this->enforce) {
            self::assertPanelSurfacesAuthorized($panel, $this->exclude);
        }

        self::authorizeRecordsOfActions();
    }

    /**
     * @param  array{resources: list<string>, pages: list<string>, widgets: list<string>}  $exclude
     */
    private static function assertPanelSurfacesAuthorized(FilamentPanel $panel, array $exclude): void
    {
        foreach ($panel->getResources() as $resource) {
            if (! is_subclass_of($resource, Resource::class) || in_array($resource, $exclude['resources'], true)) {
                continue;
            }

            if (! ClassTraits::keepsMethods($resource, AuthorizesResource::class, ['getAuthorizationResponse', 'getEloquentQuery'])) {
                throw InvalidBindingStateException::invalidConfiguration(
                    $resource.' in enforced panel "'.$panel->getId().'" must use '.AuthorizesResource::class.' without overriding its authorization methods.',
                );
            }

            self::assertRelations($resource::getRelations(), $panel);
        }

        foreach ($panel->getPages() as $page) {
            if (! is_subclass_of($page, Page::class) || in_array($page, $exclude['pages'], true)) {
                continue;
            }

            if (! ClassTraits::keepsMethods($page, AuthorizesPage::class, ['canAccess'])) {
                throw InvalidBindingStateException::invalidConfiguration(
                    $page.' in enforced panel "'.$panel->getId().'" must use '.AuthorizesPage::class.' without overriding canAccess().',
                );
            }
        }

        foreach ($panel->getWidgets() as $widget) {
            $class = $widget instanceof WidgetConfiguration ? $widget->widget : $widget;

            if (! is_subclass_of($class, Widget::class) || in_array($class, $exclude['widgets'], true)) {
                continue;
            }

            if (! ClassTraits::keepsMethods($class, AuthorizesWidget::class, ['canView'])) {
                throw InvalidBindingStateException::invalidConfiguration(
                    $class.' in enforced panel "'.$panel->getId().'" must use '.AuthorizesWidget::class.' without overriding canView().',
                );
            }
        }
    }

    /**
     * @param  array<class-string|RelationGroup|RelationManagerConfiguration>  $relations
     */
    private static function assertRelations(array $relations, FilamentPanel $panel): void
    {
        foreach ($relations as $relation) {
            if ($relation instanceof RelationGroup) {
                self::assertRelations($relation->getManagers(), $panel);

                continue;
            }

            $class = $relation instanceof RelationManagerConfiguration ? $relation->relationManager : $relation;

            if (! is_subclass_of($class, RelationManager::class)
                || ! ClassTraits::keepsMethods($class, AuthorizesRelationManager::class, ['canViewForRecord', 'getAuthorizationResponse', 'makeTable'])) {
                throw InvalidBindingStateException::invalidConfiguration(
                    $class.' in enforced panel "'.$panel->getId().'" must use '.AuthorizesRelationManager::class.' without overriding its authorization methods.',
                );
            }

            if (FilamentContext::relationResource($class) === null) {
                throw InvalidBindingStateException::invalidConfiguration(
                    $class.' in enforced panel "'.$panel->getId().'" must target a related resource using '.AuthorizesResource::class.'.',
                );
            }
        }
    }

    private static function authorizeRecordsOfActions(): void
    {
        BulkAction::configureUsing(static function (BulkAction $action): void {
            $ability = match (true) {
                $action instanceof DeleteBulkAction => 'delete',
                $action instanceof ForceDeleteBulkAction => 'force_delete',
                $action instanceof RestoreBulkAction => 'restore',
                default => null,
            };

            if ($ability === null) {
                return;
            }

            $action->fetchSelectedRecords()->authorizeIndividualRecords(static function (Model $record) use ($action, $ability): Response {
                $context = FilamentContext::serving();
                $livewire = $action->getLivewire();
                $resource = $context === null ? null : FilamentContext::resourceOf($livewire);

                if ($context !== null && $resource !== null && ! $context->excludes('resources', $resource)) {
                    return FilamentGate::resource($resource, $ability, $record);
                }

                $filament = $livewire instanceof HasActions
                    ? $livewire->getDefaultActionIndividualRecordAuthorizationResponseResolver($action)
                    : null;

                $response = $filament === null ? null : $filament($record);

                return $response instanceof Response
                    ? $response
                    : ($context?->enforced() === true ? Response::deny() : Response::allow());
            });
        });

        $visible = static function (AttachAction|AssociateAction $action): void {
            $action->recordSelectOptionsQuery(static function (Builder $query) use ($action): Builder {
                $context = FilamentContext::serving();
                $resource = FilamentContext::resourceOf($action->getLivewire());

                return $context?->enforced() !== true || $resource === null || $context->excludes('resources', $resource)
                    ? $query
                    : FilamentGate::visible($resource, $query);
            });
        };

        AttachAction::configureUsing($visible);
        AssociateAction::configureUsing($visible);
    }
}
