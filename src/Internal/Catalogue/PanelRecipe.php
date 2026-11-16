<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Catalogue;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;
use Packman\Panels\Builder\PanelProvider;
use Packman\Panels\Contracts\Registry\PanelPlugin;
use Packman\Panels\Data\Admission\PermissionDefinition;
use Packman\Panels\Data\Panel\PanelSlot;
use Packman\Panels\Exceptions\Registry\RegistryFrozenException;

/**
 * Append-only timeline of a panel's declarations, recording each setting, value, and origin.
 *
 * Precedence order: `provider` < `plugin` (by attachment order) < `configure_all` (`configureAll`) < `configure` (`configure($id)`).
 *
 * @phpstan-type Origin array{kind: 'provider'|'plugin'|'configure_all'|'configure', plugin: ?string, order: int}
 * @phpstan-type Record array{setting: string, value: mixed, origin: Origin}
 * @phpstan-type Subject array{model: class-string<Model>, guard: ?string, directory: ?string}
 */
#[Internal]
final class PanelRecipe
{
    public const string PROVIDER = 'provider';

    public const string PLUGIN = 'plugin';

    public const string CONFIGURE_ALL = 'configure_all';

    public const string CONFIGURE = 'configure';

    public const string ID = 'id';

    public const string LABEL = 'label';

    public const string DESCRIPTION = 'description';

    public const string DEFAULT = 'default';

    public const string RESOURCE_PREFIX = 'resource_prefix';

    public const string SUBJECTS = 'subjects';

    public const string MIDDLEWARE = 'middleware';

    public const string ENTRY = 'entry';

    public const string ON_DENIED = 'on_denied';

    public const string REQUIRE_ROUTE_CHECKS = 'require_route_checks';

    public const string PERMISSIONS = 'permissions';

    public const string ROLES = 'roles';

    public const string SLOTS = 'slots';

    public const string SUBJECT_RESOLVER = 'subject_resolver';

    public const string REQUIRED_CAPABILITIES = 'required_capabilities';

    public const string PRESENTATION = 'presentation';

    public const string BEFORE = 'before';

    public const string RESTRICTIONS = 'restrictions';

    public const string AFTER = 'after';

    public const string GRANT_CONDITIONS = 'grant_conditions';

    public const string DOCTOR_CHECKS = 'doctor_checks';

    public const string TENANT_MODE = 'tenant_mode';

    public const string SCOPE_MODE = 'scope_mode';

    public const string SCOPE_TYPES = 'scope_types';

    public const string OPTIONS = 'options';

    public const string PLUGINS = 'plugins';

    public const string WITHOUT_PLUGINS = 'without_plugins';

    public const array HOOK_LISTS = [
        self::BEFORE,
        self::RESTRICTIONS,
        self::AFTER,
        self::GRANT_CONDITIONS,
        self::DOCTOR_CHECKS,
    ];

    private const array LAYERS = [
        self::PROVIDER => 0,
        self::PLUGIN => 1,
        self::CONFIGURE_ALL => 2,
        self::CONFIGURE => 3,
    ];

    /** @var list<Record> */
    private array $records = [];

    /** @var Origin */
    private array $origin;

    /** @var list<string> */
    private array $pluginIds = [];

    private bool $sealed = false;

    /** @var class-string<PanelProvider>|null */
    private ?string $providerClass = null;

    public function __construct(private readonly string $panelId)
    {
        $this->origin = self::provider();
    }

    /** @return Origin */
    public static function provider(): array
    {
        return ['kind' => self::PROVIDER, 'plugin' => null, 'order' => 0];
    }

    /** @return Origin */
    public static function plugin(string $id, int $order): array
    {
        return ['kind' => self::PLUGIN, 'plugin' => $id, 'order' => $order];
    }

    /** @return Origin */
    public static function configureAll(): array
    {
        return ['kind' => self::CONFIGURE_ALL, 'plugin' => null, 'order' => 0];
    }

    /** @return Origin */
    public static function configure(): array
    {
        return ['kind' => self::CONFIGURE, 'plugin' => null, 'order' => 0];
    }

    public function panelId(): string
    {
        return $this->panelId;
    }

    /** @param class-string<PanelProvider> $provider */
    public function setProvider(string $provider): void
    {
        $this->providerClass = $provider;
    }

    /** @return class-string<PanelProvider>|null */
    public function providerClass(): ?string
    {
        return $this->providerClass;
    }

    /** @return Origin */
    public function origin(): array
    {
        return $this->origin;
    }

    /**
     * @param  Origin  $origin
     * @param  Closure(): mixed  $callback
     */
    public function during(array $origin, Closure $callback): void
    {
        $previous = $this->origin;
        $this->origin = $origin;

        try {
            $callback();
        } finally {
            $this->origin = $previous;
        }
    }

    /**
     * @throws RegistryFrozenException
     */
    public function record(string $setting, mixed $value): void
    {
        $this->assertOpen($setting);

        $this->records[] = ['setting' => $setting, 'value' => $value, 'origin' => $this->origin];
    }

    /**
     * @return Origin
     *
     * @throws RegistryFrozenException
     */
    public function attach(string $pluginId): array
    {
        $this->assertOpen(self::PLUGINS);
        $this->pluginIds[] = $pluginId;

        return self::plugin($pluginId, count($this->pluginIds));
    }

    /** @return list<string> */
    public function pluginIds(): array
    {
        return $this->pluginIds;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    /** @return list<Record> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<Record> */
    public function layered(string $setting): array
    {
        $records = array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['setting'] === $setting,
        ));
        $positions = array_keys($records);

        usort($positions, static fn (int $a, int $b): int => [self::rank($records[$a]['origin']), $a]
            <=> [self::rank($records[$b]['origin']), $b]);

        return array_map(static fn (int $position): array => $records[$position], $positions);
    }

    /**
     * @param  Origin  $origin
     * @return array{0: int, 1: int}
     */
    public static function rank(array $origin): array
    {
        return [self::LAYERS[$origin['kind']], $origin['kind'] === self::PLUGIN ? $origin['order'] : 0];
    }

    /** @return list<mixed> */
    public function items(string $setting): array
    {
        $items = [];

        foreach ($this->layered($setting) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @return list<Subject> */
    public function subjects(): array
    {
        $subjects = [];

        foreach ($this->items(self::SUBJECTS) as $subject) {
            if (is_array($subject) && is_string($subject['model'] ?? null) && is_subclass_of($subject['model'], Model::class)) {
                $guard = $subject['guard'] ?? null;
                $directory = $subject['directory'] ?? null;

                $subjects[] = [
                    'model' => $subject['model'],
                    'guard' => is_string($guard) ? $guard : null,
                    'directory' => is_string($directory) ? $directory : null,
                ];
            }
        }

        return $subjects;
    }

    /**
     * @param  self::PROVIDER|self::PLUGIN|self::CONFIGURE_ALL|self::CONFIGURE  $layer
     * @return list<PanelPlugin|class-string<PanelPlugin>>
     */
    public function plugins(string $layer): array
    {
        $plugins = [];

        foreach ($this->records as $record) {
            if ($record['setting'] !== self::PLUGINS || $record['origin']['kind'] !== $layer) {
                continue;
            }

            foreach (is_array($record['value']) ? $record['value'] : [] as $plugin) {
                if ($plugin instanceof PanelPlugin || (is_string($plugin) && is_subclass_of($plugin, PanelPlugin::class))) {
                    $plugins[] = $plugin;
                }
            }
        }

        return $plugins;
    }

    /** @return list<string> */
    public function withoutPlugins(): array
    {
        return array_values(array_unique(array_filter($this->items(self::WITHOUT_PLUGINS), is_string(...))));
    }

    /** @return list<class-string<BackedEnum>> */
    public function enums(): array
    {
        $enums = [];

        foreach ($this->items(self::PERMISSIONS) as $definition) {
            if (is_string($definition) && is_subclass_of($definition, BackedEnum::class)) {
                $enums[$definition] = $definition;
            }
        }

        return array_values($enums);
    }

    /** @return list<PermissionDefinition|string> */
    public function sources(): array
    {
        $sources = [];

        foreach ($this->items(self::PERMISSIONS) as $definition) {
            if ($definition instanceof PermissionDefinition || (is_string($definition) && ! is_subclass_of($definition, BackedEnum::class))) {
                $sources[] = $definition;
            }
        }

        return $sources;
    }

    /** @return list<string> */
    public function roles(): array
    {
        $roles = [];

        foreach ($this->items(self::ROLES) as $role) {
            if (is_string($role) && $role !== '') {
                $roles[$role] = $role;
            }
        }

        return array_values($roles);
    }

    /** @return list<PanelSlot> */
    public function slots(): array
    {
        $slots = [];

        foreach ($this->items(self::SLOTS) as $slot) {
            if ($slot instanceof PanelSlot) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * @throws RegistryFrozenException
     */
    private function assertOpen(string $setting): void
    {
        if ($this->sealed) {
            throw new RegistryFrozenException(
                'Panel "'.$this->panelId.'" is already compiled: "'.$setting.'" cannot be changed after the application has booted.',
            );
        }
    }
}
