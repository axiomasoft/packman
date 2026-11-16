<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Catalogue;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Contracts\Registry\PanelPlugin;
use Packman\Panels\Data\Panel\PanelPluginContext;
use Packman\Panels\Data\Panel\PanelSettings;
use Packman\Panels\Data\Panel\PanelSlot;
use Packman\Panels\Data\Subjects\SubjectDescriptor;
use Packman\Panels\Enums\Admission\AssignmentScopeMode;
use Packman\Panels\Enums\Admission\TenantMode;
use Packman\Panels\Enums\Panel\Reads;
use Packman\Panels\Enums\Panel\StateRefresh;
use Packman\Panels\Exceptions\Definition\DefaultPanelConflictException;
use Packman\Panels\Exceptions\Definition\DefinitionException;
use Packman\Panels\Exceptions\Definition\PluginConflictException;
use Packman\Panels\Exceptions\Definition\PrefixConflictException;
use ReflectionClass;
use UnitEnum;

/**
 * Registers the plugins of a panel, turns its sealed recipe into an immutable `Panel`, and enforces cross-panel invariants.
 *
 * @phpstan-import-type Record from PanelRecipe
 *
 * @phpstan-type Resolved array{value: mixed, origin: string}
 */
#[Internal]
final class PanelCompiler
{
    private const string PLUGIN_ID = '/\A[a-z0-9][a-z0-9_.-]*(\/[a-z0-9][a-z0-9_.-]*)?\z/';

    private const int PLUGIN_ID_BYTES = 128;

    /**
     * @param  (Closure(): array<string, bool|int|string|null>)|null  $defaults
     */
    public function __construct(private readonly ?Closure $defaults = null) {}

    /**
     * @throws DefinitionException
     * @throws PluginConflictException
     */
    public function register(PanelRecipe $recipe, PanelBuilder $builder, Container $container, string $buildId): void
    {
        $panel = $recipe->panelId();
        $detached = $recipe->withoutPlugins();
        $queue = [
            ...array_map(static fn (PanelPlugin|string $plugin): array => [$plugin, false], $recipe->plugins(PanelRecipe::PROVIDER)),
            ...array_map(static fn (PanelPlugin|string $plugin): array => [$plugin, true], $recipe->plugins(PanelRecipe::CONFIGURE_ALL)),
            ...array_map(static fn (PanelPlugin|string $plugin): array => [$plugin, false], $recipe->plugins(PanelRecipe::CONFIGURE)),
        ];
        $attached = [];
        $nested = 0;

        while ($queue !== []) {
            foreach ($queue as [$declared, $detachable]) {
                $plugin = $this->instance($panel, $declared, $container);
                $id = $this->pluginId($panel, $plugin);

                if ($detachable && in_array($id, $detached, true)) {
                    continue;
                }

                if (isset($attached[$id])) {
                    throw new PluginConflictException(
                        'Panel "'.$panel.'" has two plugins with the id "'.$id.'" ('.$attached[$id]::class.' and '
                        .$plugin::class.'): attach the plugin once.',
                    );
                }

                $this->assertIdOwned($panel, $id, $plugin);
                $context = new PanelPluginContext($panel, $id, $buildId);
                $attached[$id] = $plugin;

                $recipe->during($recipe->attach($id), static fn () => $plugin->register($builder, $context));
            }

            $byPlugins = $recipe->plugins(PanelRecipe::PLUGIN);
            $queue = array_map(static fn (PanelPlugin|string $plugin): array => [$plugin, false], array_slice($byPlugins, $nested));
            $nested = count($byPlugins);
        }

        $recipe->seal();
    }

    /**
     * @throws DefinitionException
     * @throws PluginConflictException
     * @throws InvalidConfiguration
     */
    public function compile(PanelRecipe $recipe): Panel
    {
        $id = $recipe->panelId();
        $label = $this->resolved($recipe, PanelRecipe::LABEL)['value'] ?? null;
        $description = $this->resolved($recipe, PanelRecipe::DESCRIPTION)['value'] ?? null;
        $tenantMode = $this->resolved($recipe, PanelRecipe::TENANT_MODE)['value'] ?? TenantMode::None;
        $scopeMode = $this->resolved($recipe, PanelRecipe::SCOPE_MODE)['value'] ?? AssignmentScopeMode::None;
        $subjectResolver = $this->resolved($recipe, PanelRecipe::SUBJECT_RESOLVER)['value'] ?? null;

        if (! $tenantMode instanceof TenantMode) {
            throw new DefinitionException('Panel "'.$id.'" requires a '.TenantMode::class.' value.');
        }

        if (! $scopeMode instanceof AssignmentScopeMode) {
            throw new DefinitionException('Panel "'.$id.'" requires an '.AssignmentScopeMode::class.' value.');
        }

        $subjects = $this->subjects($recipe);

        return new Panel(
            id: $id,
            label: is_string($label) ? $label : $id,
            description: is_string($description) ? $description : null,
            default: ($this->resolved($recipe, PanelRecipe::DEFAULT)['value'] ?? false) === true,
            settings: $this->settings($recipe),
            subjectModels: array_map(static fn (SubjectDescriptor $subject): string => $subject->model, $subjects),
            pluginIds: $recipe->pluginIds(),
            subjectDescriptors: $subjects,
            entryPermission: $this->entry($recipe),
            deniedResponse: $this->deniedResponse($recipe),
            entryMiddleware: array_values(array_filter(self::items($recipe, PanelRecipe::MIDDLEWARE), is_string(...))),
            routeChecks: ($this->resolved($recipe, PanelRecipe::REQUIRE_ROUTE_CHECKS)['value'] ?? false) === true,
            tenantMode: $tenantMode,
            scopeMode: $scopeMode,
            scopeTypes: array_values(array_unique(array_filter(self::items($recipe, PanelRecipe::SCOPE_TYPES), is_string(...)))),
            subjectResolver: is_string($subjectResolver) ? $subjectResolver : null,
            requiredCapabilities: array_values(array_unique(array_filter(self::items($recipe, PanelRecipe::REQUIRED_CAPABILITIES), is_string(...)))),
            slots: $this->slots($recipe),
            presentation: $this->presentation($recipe),
            beforeHooks: self::items($recipe, PanelRecipe::BEFORE),
            afterHooks: self::items($recipe, PanelRecipe::AFTER),
            accessRestrictions: self::items($recipe, PanelRecipe::RESTRICTIONS),
            conditions: self::items($recipe, PanelRecipe::GRANT_CONDITIONS),
            healthChecks: $this->doctorChecks($recipe),
            options: $this->options($recipe),
        );
    }

    /**
     * @throws PluginConflictException
     * @throws InvalidConfiguration
     */
    public function settings(PanelRecipe $recipe): PanelSettings
    {
        $defaults = [...PanelSettings::DEFAULTS, ...($this->defaults === null ? [] : ($this->defaults)())];
        $values = $origins = [];

        foreach (array_keys(PanelSettings::DEFAULTS) as $setting) {
            $resolved = $this->resolved($recipe, $setting);
            $values[$setting] = $resolved === null ? $defaults[$setting] : $resolved['value'];
            $origins[$setting] = $resolved === null ? 'default' : $resolved['origin'];
        }

        $panel = $recipe->panelId();
        $prefix = $values[PanelSettings::RESOURCE_PREFIX];
        $store = $values[PanelSettings::CACHE_STORE];
        $ttl = $values[PanelSettings::CACHE_TTL];
        $generation = $values[PanelSettings::CACHE_GENERATION];

        if ((! is_int($ttl) && $ttl !== null) || (is_int($ttl) && $ttl < 1)) {
            throw self::invalid($panel, PanelSettings::CACHE_TTL, $ttl, 'a positive number of seconds or null');
        }

        if (! is_int($generation) || $generation < 1) {
            throw self::invalid($panel, PanelSettings::CACHE_GENERATION, $generation, 'a positive integer');
        }

        if ($store !== null && $ttl === null) {
            throw new InvalidConfiguration([
                'panels.'.$panel.'.cache.ttl: Panel "'.$panel.'" caches permission sets in the store '.self::describe($store).' without a ttl.',
            ]);
        }

        return new PanelSettings(
            resourcePrefix: match (true) {
                is_string($prefix) => $prefix,
                $prefix === false => null,
                default => $panel,
            },
            cacheStore: is_string($store) ? $store : null,
            cacheTtl: $ttl,
            cacheGeneration: $generation,
            reads: self::enum(Reads::class, $panel, PanelSettings::READS, $values[PanelSettings::READS]),
            stateRefresh: self::enum(StateRefresh::class, $panel, PanelSettings::STATE_REFRESH, $values[PanelSettings::STATE_REFRESH]),
            traceDecisions: $values[PanelSettings::TRACE_DECISIONS] === true,
            origins: $origins,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PluginConflictException
     */
    public function presentation(PanelRecipe $recipe): array
    {
        $byKey = [];

        foreach ($recipe->layered(PanelRecipe::PRESENTATION) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $key => $value) {
                $byKey[(string) $key][] = ['setting' => $record['setting'], 'value' => $value, 'origin' => $record['origin']];
            }
        }

        $options = [];

        foreach ($byKey as $key => $records) {
            $options[$key] = $this->pick($recipe->panelId(), 'presentation.'.$key, $records)['value'];
        }

        return $options;
    }

    /** @return list<mixed> */
    public static function items(PanelRecipe $recipe, string $setting): array
    {
        $items = $seen = [];

        foreach ($recipe->items($setting) as $item) {
            if (is_string($item)) {
                if (isset($seen[$item])) {
                    continue;
                }

                $seen[$item] = true;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, Panel>  $panels
     *
     * @throws DefaultPanelConflictException
     */
    public function assertDefaults(array $panels): void
    {
        $defaults = array_values(array_filter($panels, static fn (Panel $panel): bool => $panel->isDefault()));

        foreach ($defaults as $position => $first) {
            foreach (array_slice($defaults, $position + 1) as $second) {
                $shared = $this->sharedModel($first, $second);

                if ($shared !== null) {
                    throw new DefaultPanelConflictException(
                        'Panels "'.$first->id().'" and "'.$second->id().'" are both the default panel of '.$shared
                        .': keep default() on one of them.',
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, Panel>  $panels
     * @param  array<string, PanelCatalog>  $catalogs
     * @return array<string, string>
     *
     * @throws PrefixConflictException
     */
    public function prefixes(array $panels, array $catalogs = []): array
    {
        $prefixes = [];

        foreach ($panels as $panel) {
            $prefix = $panel->prefix();

            if ($prefix === null) {
                continue;
            }

            if (isset($prefixes[$prefix])) {
                throw new PrefixConflictException(
                    'Panels "'.$prefixes[$prefix].'" and "'.$panel->id().'" both use the permission prefix "'.$prefix
                    .'": give one of them another resourcePrefix() or turn it off with resourcePrefix(false).',
                );
            }

            $prefixes[$prefix] = $panel->id();
        }

        foreach ($catalogs as $catalog) {
            foreach ($prefixes as $prefix => $owner) {
                $name = $catalog->nameWithFirstSegment($prefix);

                if ($name !== null) {
                    throw new PrefixConflictException(
                        'The permission prefix "'.$prefix.'" of panel "'.$owner.'" is the first segment of the permission "'.$name
                        .'" of panel "'.$catalog->panel().'": give the panel another resourcePrefix() or rename the permission.',
                    );
                }
            }
        }

        return $prefixes;
    }

    /**
     * @return array<class-string, object>
     *
     * @throws PluginConflictException
     */
    private function options(PanelRecipe $recipe): array
    {
        $byClass = [];

        foreach ($recipe->layered(PanelRecipe::OPTIONS) as $record) {
            if (is_object($record['value'])) {
                $byClass[$record['value']::class][] = $record;
            }
        }

        $options = [];

        foreach ($byClass as $class => $records) {
            $value = $this->pick($recipe->panelId(), 'options '.$class, $records)['value'];

            if (is_object($value)) {
                $options[$class] = $value;
            }
        }

        ksort($options, SORT_STRING);

        return $options;
    }

    /**
     * @return array<string, PanelSlot>
     *
     * @throws DefinitionException
     */
    private function slots(PanelRecipe $recipe): array
    {
        $indexed = [];

        foreach ($recipe->slots() as $slot) {
            if (isset($indexed[$slot->id])) {
                throw new DefinitionException('Panel "'.$recipe->panelId().'" declares duplicate slot "'.$slot->id.'".');
            }

            $indexed[$slot->id] = $slot;
        }

        ksort($indexed, SORT_STRING);

        return $indexed;
    }

    /**
     * @return list<array{check: mixed, origin: string}>
     */
    private function doctorChecks(PanelRecipe $recipe): array
    {
        $checks = [];

        foreach ($recipe->layered(PanelRecipe::DOCTOR_CHECKS) as $record) {
            $origin = $record['origin']['kind'] === PanelRecipe::PLUGIN ? PanelRecipe::PLUGIN.':'.$record['origin']['plugin'] : $record['origin']['kind'];

            foreach (is_array($record['value']) ? $record['value'] : [] as $check) {
                $checks[] = ['check' => $check, 'origin' => $origin];
            }
        }

        return $checks;
    }

    /**
     * @throws PluginConflictException
     */
    private function entry(PanelRecipe $recipe): string|UnitEnum|null
    {
        $entry = $this->resolved($recipe, PanelRecipe::ENTRY)['value'] ?? null;

        return is_string($entry) || $entry instanceof UnitEnum ? $entry : null;
    }

    /**
     * @throws PluginConflictException
     */
    private function deniedResponse(PanelRecipe $recipe): Closure|string|null
    {
        $response = $this->resolved($recipe, PanelRecipe::ON_DENIED)['value'] ?? null;

        return is_string($response) || $response instanceof Closure ? $response : null;
    }

    /**
     * @return list<SubjectDescriptor>
     *
     * @throws DefinitionException
     */
    private function subjects(PanelRecipe $recipe): array
    {
        $byModel = [];

        foreach ($recipe->subjects() as $subject) {
            $known = $byModel[$subject['model']] ?? null;

            if ($known === null) {
                $byModel[$subject['model']] = new SubjectDescriptor($subject['model'], $subject['guard'], $subject['directory']);

                continue;
            }

            foreach (['guard', 'directory'] as $setting) {
                if ($subject[$setting] !== null && $known->{$setting} !== null && $subject[$setting] !== $known->{$setting}) {
                    throw new DefinitionException('Panel '.$recipe->panelId().' declares subject '.$subject['model'].' with two '.$setting.'s: '
                        .$known->{$setting}.' and '.$subject[$setting].'.');
                }
            }

            $byModel[$subject['model']] = new SubjectDescriptor($subject['model'], $known->guard ?? $subject['guard'], $known->directory ?? $subject['directory']);
        }

        return array_values($byModel);
    }

    /**
     * @param  PanelPlugin|class-string<PanelPlugin>  $declared
     *
     * @throws DefinitionException
     */
    private function instance(string $panel, PanelPlugin|string $declared, Container $container): PanelPlugin
    {
        if ($declared instanceof PanelPlugin) {
            return clone $declared;
        }

        $plugin = $container->make($declared);

        if (! $plugin instanceof PanelPlugin) {
            throw new DefinitionException(
                'Panel "'.$panel.'": the container resolved '.$declared.' to '.get_debug_type($plugin).', which is not a panel plugin.',
            );
        }

        return clone $plugin;
    }

    /**
     * @throws DefinitionException
     */
    private function pluginId(string $panel, PanelPlugin $plugin): string
    {
        $id = $plugin->id();

        if (strlen($id) > self::PLUGIN_ID_BYTES || preg_match(self::PLUGIN_ID, $id) !== 1) {
            throw new DefinitionException(
                'Panel "'.$panel.'": '.$plugin::class.' has the plugin id '.self::describe($id).'; an id is "name" or "vendor/name" of '
                .'lowercase letters, digits, ".", "_" and "-", at most '.self::PLUGIN_ID_BYTES.' bytes.',
            );
        }

        return $id;
    }

    /**
     * @throws PluginConflictException
     */
    private function assertIdOwned(string $panel, string $id, PanelPlugin $plugin): void
    {
        if (! str_starts_with($id, 'packman/')) {
            return;
        }

        if (new ReflectionClass($plugin)->isAnonymous() || ! str_starts_with($plugin::class, 'Packman\\')) {
            throw new PluginConflictException(
                'Panel "'.$panel.'": '.$plugin::class.' has the plugin id "'.$id.'", but the prefix "packman/" is reserved for plugins of the package; use your own vendor name.',
            );
        }
    }

    /**
     * @return Resolved|null
     *
     * @throws PluginConflictException
     */
    private function resolved(PanelRecipe $recipe, string $setting): ?array
    {
        $records = $recipe->layered($setting);

        return $records === [] ? null : $this->pick($recipe->panelId(), $setting, $records);
    }

    /**
     * @param  non-empty-list<Record>  $records
     * @return Resolved
     *
     * @throws PluginConflictException
     */
    private function pick(string $panel, string $setting, array $records): array
    {
        $layers = [
            PanelRecipe::PROVIDER => [],
            PanelRecipe::PLUGIN => [],
            PanelRecipe::CONFIGURE_ALL => [],
            PanelRecipe::CONFIGURE => [],
        ];

        foreach ($records as $record) {
            $layers[$record['origin']['kind']][] = $record;
        }

        if ($layers[PanelRecipe::CONFIGURE] !== []) {
            return ['value' => end($layers[PanelRecipe::CONFIGURE])['value'], 'origin' => PanelRecipe::CONFIGURE];
        }

        $configureAll = self::nonNullRecords($layers[PanelRecipe::CONFIGURE_ALL]);

        if ($configureAll !== []) {
            return ['value' => end($configureAll)['value'], 'origin' => PanelRecipe::CONFIGURE_ALL];
        }

        $plugins = self::nonNullRecords($layers[PanelRecipe::PLUGIN]);

        if ($plugins !== []) {
            return $this->pickPlugin($panel, $setting, $plugins);
        }

        if ($layers[PanelRecipe::PROVIDER] !== []) {
            return ['value' => end($layers[PanelRecipe::PROVIDER])['value'], 'origin' => PanelRecipe::PROVIDER];
        }

        if ($layers[PanelRecipe::CONFIGURE_ALL] !== []) {
            return ['value' => null, 'origin' => PanelRecipe::CONFIGURE_ALL];
        }

        $firstPlugin = $layers[PanelRecipe::PLUGIN][0] ?? null;

        return ['value' => null, 'origin' => $firstPlugin === null ? PanelRecipe::PROVIDER : PanelRecipe::PLUGIN.':'.$firstPlugin['origin']['plugin']];
    }

    /**
     * @param  non-empty-list<Record>  $records
     * @return Resolved
     *
     * @throws PluginConflictException
     */
    private function pickPlugin(string $panel, string $setting, array $records): array
    {
        $byPlugin = [];

        foreach ($records as $record) {
            $byPlugin[(string) $record['origin']['plugin']] = $record['value'];
        }

        $first = array_key_first($byPlugin);

        foreach ($byPlugin as $plugin => $value) {
            $firstValue = $byPlugin[$first];
            $same = (is_object($value) && is_object($firstValue) && ! $value instanceof Closure)
                ? ($value === $firstValue || ($value::class === $firstValue::class && (array) $value === (array) $firstValue))
                : ($value === $firstValue);

            if (! $same) {
                throw new PluginConflictException(
                    'Plugins "'.$first.'" and "'.$plugin.'" of panel "'.$panel.'" set "'.$setting.'" to different values ('
                    .self::describe($byPlugin[$first]).' and '.self::describe($value).'): set it via configure() or configureAll().',
                );
            }
        }

        return ['value' => $byPlugin[$first], 'origin' => PanelRecipe::PLUGIN.':'.$first];
    }

    /**
     * @param  list<Record>  $records
     * @return list<Record>
     */
    private static function nonNullRecords(array $records): array
    {
        return array_values(array_filter(
            $records,
            static fn (array $record): bool => $record['value'] !== null,
        ));
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum
     *
     * @throws InvalidConfiguration
     */
    private static function enum(string $enum, string $panel, string $setting, mixed $value): BackedEnum
    {
        $case = match (true) {
            $value instanceof $enum => $value,
            is_string($value) => $enum::tryFrom($value),
            default => null,
        };

        return $case ?? throw self::invalid(
            $panel,
            $setting,
            $value,
            'one of '.implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases())),
        );
    }

    private static function invalid(string $panel, string $setting, mixed $value, string $expected): InvalidConfiguration
    {
        return new InvalidConfiguration([
            'panels.'.$panel.'.'.$setting.': setting "'.$setting.'" is '.self::describe($value).', expected '.$expected.'.',
        ]);
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value) || $value === null => (string) json_encode($value),
            default => get_debug_type($value),
        };
    }

    private function sharedModel(Panel $first, Panel $second): ?string
    {
        foreach ($first->subjectModels() as $model) {
            foreach ($second->subjectModels() as $other) {
                if (is_a($model, $other, true) || is_a($other, $model, true)) {
                    return is_a($model, $other, true) ? $model : $other;
                }
            }
        }

        return null;
    }
}
