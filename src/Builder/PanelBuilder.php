<?php

declare(strict_types=1);

namespace Packman\Panels\Builder;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Panels\Contracts\Registry\PanelPlugin;
use Packman\Panels\Data\Admission\PermissionDefinition;
use Packman\Panels\Data\Panel\PanelSettings;
use Packman\Panels\Data\Panel\PanelSlot;
use Packman\Panels\Enums\Admission\AssignmentScopeMode;
use Packman\Panels\Enums\Admission\TenantMode;
use Packman\Panels\Enums\Panel\Reads;
use Packman\Panels\Enums\Panel\StateRefresh;
use Packman\Panels\Exceptions\Definition\DefinitionException;
use Packman\Panels\Exceptions\Registry\RegistryFrozenException;
use Packman\Panels\Internal\Catalogue\CanonicalMetadata;
use Packman\Panels\Internal\Catalogue\PanelRecipe;
use ReflectionClass;
use ReflectionEnum;
use UnitEnum;

/**
 * Describes a panel inside `PanelProvider::panel()` and in `configure` callbacks.
 *
 * Every call is written to the panel recipe together with its origin; the builder exposes nothing to read back.
 * Once the panel is compiled the builder is sealed and any further call throws `RegistryFrozenException`.
 */
#[Api(since: '1.0')]
final class PanelBuilder
{
    private const string SEGMENT_PATTERN = '/\A[a-z0-9][a-z0-9_-]*\z/';

    /**
     * @internal built by the panel registry
     */
    #[Internal('Constructed by the package registrar.')]
    public function __construct(private readonly PanelRecipe $recipe) {}

    /**
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function id(string $id): static
    {
        if ($id !== $this->recipe->panelId()) {
            throw new DefinitionException(
                'Panel "'.$this->recipe->panelId().'" cannot take the id "'.$id.'": the id is the value of getId() of its provider.',
            );
        }

        return $this->record(PanelRecipe::ID, $id);
    }

    /**
     * @throws RegistryFrozenException
     */
    public function label(string $label): static
    {
        return $this->record(PanelRecipe::LABEL, $label);
    }

    /**
     * @throws RegistryFrozenException
     */
    public function description(?string $description): static
    {
        return $this->record(PanelRecipe::DESCRIPTION, $description);
    }

    /**
     * @throws RegistryFrozenException
     */
    public function default(bool $default = true): static
    {
        return $this->record(PanelRecipe::DEFAULT, $default);
    }

    /**
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function resourcePrefix(string|bool $prefix = true): static
    {
        if (is_string($prefix) && preg_match(self::SEGMENT_PATTERN, $prefix) !== 1) {
            throw $this->invalid('resourcePrefix() expects one name segment such as "backoffice", got '.self::describe($prefix));
        }

        return $this->record(PanelRecipe::RESOURCE_PREFIX, $prefix);
    }

    /**
     * Declares subject models of the panel; repeated calls add models.
     *
     * @param  class-string<Model>|list<class-string<Model>>  $model
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function for(string|array $model, ?string $guard = null, ?string $directory = null): static
    {
        $models = self::input(is_array($model) ? $model : [$model]);

        if ($models === [] || ! array_is_list($models)) {
            throw $this->invalid('for() expects a model class or a non-empty list of model classes');
        }

        if ($guard === '' || $directory === '') {
            throw $this->invalid('for() expects a non-empty guard and directory when they are given');
        }

        $subjects = [];

        foreach ($models as $class) {
            if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                throw $this->invalid('for() expects Eloquent model classes, got '.self::describe($class));
            }

            $subjects[] = ['model' => ltrim($class, '\\'), 'guard' => $guard, 'directory' => $directory];
        }

        return $this->record(PanelRecipe::SUBJECTS, $subjects);
    }

    /**
     * @param  list<string>  $middleware
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function middleware(array $middleware): static
    {
        $names = [];

        foreach (self::input($middleware) as $name) {
            if (! is_string($name) || $name === '') {
                throw $this->invalid('middleware() expects middleware names or classes, got '.self::describe($name));
            }

            $names[] = $name;
        }

        return $this->record(PanelRecipe::MIDDLEWARE, $names);
    }

    /**
     * @throws RegistryFrozenException
     */
    public function entry(string|UnitEnum|null $permission): static
    {
        return $this->record(PanelRecipe::ENTRY, $permission);
    }

    /**
     * @throws RegistryFrozenException
     */
    public function onDenied(Closure|string|null $response): static
    {
        return $this->record(PanelRecipe::ON_DENIED, $response);
    }

    /**
     * @throws RegistryFrozenException
     */
    public function requireRouteChecks(): static
    {
        return $this->record(PanelRecipe::REQUIRE_ROUTE_CHECKS, true);
    }

    /**
     * @param  list<PermissionDefinition|class-string<BackedEnum>|string>  $definitions
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function permissions(array $definitions): static
    {
        return $this->record(PanelRecipe::PERMISSIONS, array_map($this->definition(...), array_values(self::input($definitions))));
    }

    /**
     * @param  list<class-string|string>  $roles
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function roles(array $roles): static
    {
        $names = [];

        foreach (self::input($roles) as $role) {
            if (! is_string($role) || $role === '') {
                throw $this->invalid('roles() expects non-empty role names or class names, got '.self::describe($role));
            }

            $names[] = ltrim($role, '\\');
        }

        return $this->record(PanelRecipe::ROLES, $names);
    }

    /**
     * @param  list<PanelSlot>  $slots
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function slots(array $slots): static
    {
        $validated = [];

        foreach (self::input($slots) as $slot) {
            if (! $slot instanceof PanelSlot) {
                throw $this->invalid('slots() expects '.PanelSlot::class.' objects, got '.self::describe($slot));
            }

            $validated[] = $slot;
        }

        return $this->record(PanelRecipe::SLOTS, $validated);
    }

    /**
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function subjectResolver(string $resolverId): static
    {
        PanelSlot::identifier($resolverId);

        return $this->record(PanelRecipe::SUBJECT_RESOLVER, $resolverId);
    }

    /**
     * @param  list<string>  $capabilities
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function requiredCapabilities(array $capabilities): static
    {
        $names = [];

        foreach (self::input($capabilities) as $capability) {
            if (! is_string($capability) || $capability === '') {
                throw $this->invalid('requiredCapabilities() expects non-empty capability names, got '.self::describe($capability));
            }

            $names[] = $capability;
        }

        return $this->record(PanelRecipe::REQUIRED_CAPABILITIES, $names);
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws RegistryFrozenException
     */
    public function presentation(array $options): static
    {
        return $this->record(PanelRecipe::PRESENTATION, $options);
    }

    /**
     * @param  list<object|class-string>|object|class-string  $hooks
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function before(array|object|string $hooks): static
    {
        return $this->hooks(PanelRecipe::BEFORE, 'before', is_array($hooks) ? $hooks : [$hooks]);
    }

    /**
     * @param  array<mixed>  $restrictions
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function restrictions(array $restrictions): static
    {
        return $this->hooks(PanelRecipe::RESTRICTIONS, 'restrictions', $restrictions);
    }

    /**
     * @param  list<object|class-string>|object|class-string  $hooks
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function after(array|object|string $hooks): static
    {
        return $this->hooks(PanelRecipe::AFTER, 'after', is_array($hooks) ? $hooks : [$hooks]);
    }

    /**
     * @param  array<mixed>  $conditions
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function grantConditions(array $conditions): static
    {
        return $this->hooks(PanelRecipe::GRANT_CONDITIONS, 'grantConditions', $conditions);
    }

    /**
     * @param  array<mixed>  $checks
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function doctorChecks(array $checks): static
    {
        return $this->hooks(PanelRecipe::DOCTOR_CHECKS, 'doctorChecks', $checks);
    }

    /**
     * Typed readonly module options, one object per class per layer; read them with `Panel::options(Class)`.
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function options(object $options): static
    {
        if (! new ReflectionClass($options)->isReadOnly()) {
            throw $this->invalid('options() expects a readonly object, got '.$options::class);
        }

        $origin = $this->recipe->origin();

        foreach ($this->recipe->records() as $record) {
            if ($record['setting'] === PanelRecipe::OPTIONS && $record['origin'] === $origin && is_object($record['value']) && $options::class === $record['value']::class) {
                throw $this->invalid('options() takes one object of '.$options::class.' per layer');
            }
        }

        try {
            CanonicalMetadata::assertOptions($options);
        } catch (DefinitionException $error) {
            throw $this->invalid('options() '.$options::class.': '.rtrim($error->getMessage(), '.'));
        }

        return $this->record(PanelRecipe::OPTIONS, $options);
    }

    public function panelId(): string
    {
        return $this->recipe->panelId();
    }

    /**
     * @throws RegistryFrozenException
     */
    public function cache(?string $store = null, ?int $ttl = null, ?int $generation = null): static
    {
        foreach ([PanelSettings::CACHE_STORE => $store, PanelSettings::CACHE_TTL => $ttl, PanelSettings::CACHE_GENERATION => $generation] as $setting => $value) {
            if ($value !== null) {
                $this->recipe->record($setting, $value);
            }
        }

        return $this;
    }

    /**
     * @throws RegistryFrozenException
     */
    public function consistency(Reads $reads = Reads::Primary, StateRefresh $refresh = StateRefresh::Request): static
    {
        return $this->record(PanelSettings::READS, $reads)->record(PanelSettings::STATE_REFRESH, $refresh);
    }

    /**
     * @throws RegistryFrozenException
     */
    public function tenantMode(TenantMode $mode): static
    {
        return $this->record(PanelRecipe::TENANT_MODE, $mode);
    }

    /**
     * @throws RegistryFrozenException
     */
    public function scopeMode(AssignmentScopeMode $mode): static
    {
        return $this->record(PanelRecipe::SCOPE_MODE, $mode);
    }

    /**
     * @param  list<string>  $types
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function scopeTypes(array $types): static
    {
        $normalized = [];

        foreach (self::input($types) as $type) {
            if (! is_string($type) || $type === '') {
                throw $this->invalid('scopeTypes() expects non-empty scope type strings, got '.self::describe($type));
            }

            $normalized[] = $type;
        }

        return $this->record(PanelRecipe::SCOPE_TYPES, $normalized);
    }

    /**
     * @param  list<PanelPlugin|class-string<PanelPlugin>>  $plugins
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function plugins(array $plugins): static
    {
        return $this->record(
            PanelRecipe::PLUGINS,
            array_map(fn (mixed $plugin): object|string => $this->resolver('plugins', $plugin, PanelPlugin::class), array_values(self::input($plugins))),
        );
    }

    /**
     * Keeps plugins that `configureAll()` attaches to every panel off this panel; a plugin cannot detach another plugin.
     *
     * @param  list<string>  $pluginIds
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    public function withoutPlugins(array $pluginIds): static
    {
        if ($this->recipe->origin()['kind'] === PanelRecipe::PLUGIN) {
            throw $this->invalid('withoutPlugins() belongs to the panel provider and configure callbacks: a plugin cannot detach another plugin');
        }

        $ids = [];

        foreach (self::input($pluginIds) as $id) {
            if (! is_string($id) || $id === '') {
                throw $this->invalid('withoutPlugins() expects plugin ids, got '.self::describe($id));
            }

            $ids[] = $id;
        }

        return $this->record(PanelRecipe::WITHOUT_PLUGINS, $ids);
    }

    /**
     * @throws RegistryFrozenException
     */
    private function record(string $setting, mixed $value): static
    {
        $this->recipe->record($setting, $value);

        return $this;
    }

    /**
     * @throws DefinitionException
     */
    private function definition(mixed $definition): PermissionDefinition|string
    {
        if ($definition instanceof PermissionDefinition) {
            return $definition;
        }

        if (! is_string($definition) || $definition === '') {
            throw $this->invalid(
                'permissions() expects string-backed enum classes, '.PermissionDefinition::class.' objects, or source names, got '.self::describe($definition),
            );
        }

        $name = ltrim($definition, '\\');

        if (enum_exists($name)) {
            if (new ReflectionEnum($name)->getBackingType()?->getName() !== 'string') {
                throw $this->invalid('permissions() expects a string-backed enum, '.$name.' is not one');
            }

            return $name;
        }

        if (str_contains($name, '\\') || class_exists($name) || interface_exists($name) || trait_exists($name)) {
            throw $this->invalid(
                'permissions() got the class name '.$name.', which is not a string-backed enum',
            );
        }

        return $name;
    }

    /**
     * @param  array<mixed>  $hooks
     *
     * @throws DefinitionException
     * @throws RegistryFrozenException
     */
    private function hooks(string $setting, string $method, array $hooks): static
    {
        foreach ($hooks as $hook) {
            if (! is_object($hook) && (! is_string($hook) || $hook === '')) {
                throw $this->invalid($method.'() expects class names, objects or closures, got '.self::describe($hook));
            }
        }

        return $this->record($setting, array_values($hooks));
    }

    /**
     * @param  class-string  $contract
     *
     * @throws DefinitionException
     */
    private function resolver(string $method, mixed $resolver, string $contract): object|string
    {
        if ($resolver instanceof $contract) {
            return $resolver;
        }

        if (is_string($resolver) && is_subclass_of($resolver, $contract)) {
            return ltrim($resolver, '\\');
        }

        throw $this->invalid($method.'() expects objects or classes that implement '.$contract.', got '.self::describe($resolver));
    }

    /**
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    private static function input(array $input): array
    {
        return $input;
    }

    private function invalid(string $problem): DefinitionException
    {
        return new DefinitionException('Panel "'.$this->recipe->panelId().'": '.$problem.'.');
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) ? '"'.$value.'"' : get_debug_type($value);
    }
}
