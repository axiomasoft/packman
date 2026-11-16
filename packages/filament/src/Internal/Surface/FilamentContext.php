<?php

declare(strict_types=1);

namespace Packman\Filament\Internal\Surface;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel as FilamentPanel;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Filament\Resources\ResourceConfiguration;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use Packman\Filament\Concerns\Authorization\AuthorizesResource;
use Packman\Filament\Contracts\FilamentPanelPlugin;
use Packman\Filament\Exceptions\Binding\InvalidBindingStateException;
use Packman\Filament\Internal\Reflection\ClassTraits;
use Packman\Filament\PackmanFilamentPlugin;
use ReflectionProperty;
use UnitEnum;

/**
 * Active Filament panel paired with its `FilamentPanelPlugin` instance and key/surface helpers.
 */
#[Internal]
final readonly class FilamentContext
{
    private function __construct(
        public FilamentPanel $panel,
        public FilamentPanelPlugin $plugin,
    ) {}

    public static function current(): ?self
    {
        return self::filament() ? self::of(Filament::getCurrentOrDefaultPanel()) : null;
    }

    public static function serving(): ?self
    {
        return self::filament() ? self::of(Filament::getCurrentPanel()) : null;
    }

    public static function of(?FilamentPanel $panel): ?self
    {
        if (! $panel instanceof FilamentPanel) {
            return null;
        }

        if ($panel->hasPlugin(PackmanFilamentPlugin::ID)) {
            $plugin = $panel->getPlugin(PackmanFilamentPlugin::ID);

            if ($plugin instanceof FilamentPanelPlugin) {
                return new self($panel, $plugin);
            }
        }

        foreach ($panel->getPlugins() as $plugin) {
            if ($plugin instanceof FilamentPanelPlugin) {
                return new self($panel, $plugin);
            }
        }

        return null;
    }

    public function enforced(): bool
    {
        return $this->plugin->isEnforced();
    }

    /**
     * @param  'resources'|'pages'|'widgets'  $surface
     */
    public function excludes(string $surface, string $class): bool
    {
        return in_array($class, $this->plugin->getExclude()[$surface], true);
    }

    public function guardPanel(): ?string
    {
        return $this->plugin->getGuardPanel();
    }

    /**
     * Normalized snake_case ability name for a Filament action or enum.
     */
    public static function ability(string|UnitEnum $action): string
    {
        $name = match (true) {
            $action instanceof BackedEnum => (string) $action->value,
            $action instanceof UnitEnum => $action->name,
            default => $action,
        };

        return Str::snake($name);
    }

    /**
     * @param  class-string<FilamentResource>  $resource
     */
    public function resourceKey(string $resource, string|UnitEnum $action): string
    {
        $override = self::keyOverride($resource);

        if ($override !== null) {
            return $override.'.'.self::ability($action);
        }

        $configuration = $resource::getConfiguration($this->panel);
        $slug = $resource::getDefaultSlug();

        if ($configuration instanceof ResourceConfiguration) {
            $configuredSlug = $configuration->getSlug();
            $slug = is_string($configuredSlug) && $configuredSlug !== '' ? $configuredSlug : $slug.'/'.$configuration->getKey();
        }

        return $this->segment($this->clustered($resource::getCluster(), $slug), $resource).'.'.self::ability($action);
    }

    /**
     * @param  class-string<Page>  $page
     */
    public function pageKey(string $page): string
    {
        $override = self::keyOverride($page);

        if ($override !== null) {
            return 'pages.'.$override;
        }

        $configuration = $page::getConfiguration($this->panel);
        $suffix = $configuration === null ? null : ($configuration->getSlug() ?? $configuration->getKey());
        $slug = $suffix === null ? $page::getDefaultSlug() : $page::getDefaultSlug().'/'.$suffix;

        return 'pages.'.$this->segment($this->clustered($page::getCluster(), $slug), $page);
    }

    /**
     * @param  class-string<Widget>  $widget
     */
    public function widgetKey(string $widget): string
    {
        $segment = self::keyOverride($widget) ?? Str::kebab(class_basename($widget));

        return 'widgets.'.$this->segment($segment, $widget);
    }

    /**
     * @return class-string<FilamentResource>|null
     */
    public static function resourceOf(mixed $component): ?string
    {
        $resource = match (true) {
            $component instanceof RelationManager => self::relationResource($component::class),
            $component instanceof ManageRelatedRecords => $component::getRelatedResource() ?? $component::getResource(),
            $component instanceof ResourcePage => $component::getResource(),
            default => null,
        };

        return $resource !== null && self::authorizes($resource) ? $resource : null;
    }

    /**
     * @param  class-string<RelationManager>  $relationManager
     * @return class-string<FilamentResource>|null
     */
    public static function relationResource(string $relationManager): ?string
    {
        $resource = $relationManager::getRelatedResource();

        if ($resource === null && property_exists($relationManager, 'packmanResource')) {
            $resource = new ReflectionProperty($relationManager, 'packmanResource')->getValue();
        }

        return is_string($resource) && self::authorizes($resource) ? $resource : null;
    }

    /**
     * @return array{class-string<FilamentResource>, Model}|null
     */
    public static function ownerOf(mixed $component): ?array
    {
        [$page, $record] = match (true) {
            $component instanceof RelationManager => [$component->getPageClass(), $component->getOwnerRecord()],
            $component instanceof ManageRelatedRecords => [$component::class, $component->getRecord()],
            default => [null, null],
        };

        $resource = is_string($page) && is_a($page, ResourcePage::class, true) ? $page::getResource() : null;

        return is_string($resource) && is_subclass_of($resource, FilamentResource::class) && $record instanceof Model
            ? [$resource, $record]
            : null;
    }

    /**
     * @param  class-string  $manager
     */
    public static function relationAbility(string $manager): string
    {
        if (! property_exists($manager, 'packmanRelationAbility')) {
            return 'update';
        }

        $ability = new ReflectionProperty($manager, 'packmanRelationAbility')->getValue();

        if ($ability === null) {
            return 'update';
        }

        if (! is_string($ability) || preg_match('/\A[a-z][a-z0-9_]*\z/', $ability) !== 1) {
            throw InvalidBindingStateException::invalidConfiguration(
                $manager.' declares packmanRelationAbility "'.(is_string($ability) ? $ability : get_debug_type($ability)).'", which is not snake_case.',
            );
        }

        return $ability;
    }

    /**
     * @phpstan-assert-if-true class-string<FilamentResource> $resource
     */
    public static function authorizes(string $resource): bool
    {
        return is_subclass_of($resource, FilamentResource::class) && ClassTraits::has($resource, AuthorizesResource::class);
    }

    private static function filament(): bool
    {
        return app()->bound('filament');
    }

    /**
     * @param  class-string<Cluster>|null  $cluster
     */
    private function clustered(?string $cluster, string $slug): string
    {
        return $cluster !== null ? $cluster::getSlug($this->panel).'/'.$slug : $slug;
    }

    private function segment(string $slug, string $class): string
    {
        $segment = str_replace('/', '.', trim($slug, '/'));

        foreach (explode('.', $segment) as $part) {
            if ($part === '' || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $part) !== 1) {
                throw InvalidBindingStateException::invalidConfiguration(
                    $class.' in panel "'.$this->panel->getId().'" has invalid permission segment "'.$slug.'".',
                );
            }
        }

        return $segment;
    }

    /**
     * @param  class-string  $class
     */
    private static function keyOverride(string $class): ?string
    {
        if (! property_exists($class, 'packmanKey')) {
            return null;
        }

        $key = new ReflectionProperty($class, 'packmanKey')->getValue();

        if ($key === null) {
            return null;
        }

        if (! is_string($key) || $key === '') {
            throw InvalidBindingStateException::invalidConfiguration($class.': $packmanKey must be a non-empty string or null.');
        }

        foreach (explode('.', $key) as $part) {
            if ($part === '' || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $part) !== 1) {
                throw InvalidBindingStateException::invalidConfiguration($class.': $packmanKey contains invalid permission segment.');
            }
        }

        return $key;
    }
}
