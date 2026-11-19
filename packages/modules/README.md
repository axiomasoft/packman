# axiomasoft/packman-modules

Compile Composer-discovered module declarations into a registry for Laravel. Applications can supply their own discovery and activation policies. The package also provides a PostgreSQL lifecycle for persistent activation, record ownership and recovery, and PHPStan rules for module isolation.

## Discovery & Configuration

By default, `ModuleDiscovery` scans `vendor/composer/installed.json` for packages with types `packman-module`, `packman-plugin`, `module`, or `plugin`, resolving `ModuleDefinition` subclasses from `extra.packman.module`, `extra.packman.plugin`, `extra.module`, or `extra.plugin`. Configure discovery, table names, cache paths, and lock keys in `config/modules.php`. A scoped `packman.modules` override replaces each supplied discovery list in full. Hosts can extend the standard lists using the public discovery contract, for example in `config/modules.php`:

```php
use Packman\Modules\Contracts\Definition\ModuleDiscovery;

return [
    'package_types' => ['acme-module', ...ModuleDiscovery::DEFAULT_PACKAGE_TYPES],
    'extra_keys' => [['acme', 'module'], ...ModuleDiscovery::DEFAULT_EXTRA_KEYS],
];
```

Modules may also compose kernel `Packman\Extensions\Contracts\*\With*` contracts and traits. During discovery, `ModuleDiscovery` uses `ExtensionInspector` to hydrate `WithSchema::tablePrefix`, `WithDatabaseSettings` (`settingsNamespace`, `defaultSettings`, `sensitiveSettings`), `WithFeatureFlags`, `WithLifecycleHooks` (`onEnableHook`, `onDisableHook`, `onPurgeHook`), and `WithPanels` (`panelProviders`) onto `ModuleManifest`. `WithConfiguration` exposes config/options metadata (`configRoot`, `defaultConfigPath`, `optionsClass`, `configSectionClass`) for `ExtensionInspector` and host service providers rather than auto-registering Laravel config files during discovery.

## Modules without database settings

A code-only module can return `ModuleManifest::make('acme/reports')->title('Reports')`. Declare `tablePrefix()` when contributing an entity. A module that contributes settings can declare its own `settingsNamespace()` or use its existing table prefix. Implement `WithConfiguration` for file-backed options and `WithDatabaseSettings` when the host needs persistent setting definitions.

The default discovery implementation reads Composer metadata. To compile modules from another source, bind `Packman\Modules\Contracts\Definition\ModuleDiscovery` in the application. For activation supplied by configuration or another service, bind `Packman\Modules\Contracts\Registry\ModuleStateReader`. The registry calls the reader for the requested scope. Implement `VersionedModuleStateReader` when the host can also supply a revision that changes with the enabled set, allowing the registry to cache the result. The default SQL reader uses the lifecycle log for its revision. Lifecycle mutations through `ModuleLifecycle` use the database-backed implementation by default.

## Declare contributions

The public builders in `Packman\Modules\Data\Extenders` include `Menu`, `Filament`, `Routes`, and `Workflow`. Use `Data\Lifecycle\ModuleDependency` to declare dependencies and `Data\Settings\DefaultSettingDefinition` for scoped defaults. The compiler and the registry use these same types. Compiled registries continue to use the `packman-registry/1` wire format.

An entity may declare any `Packman\Modules\Enums\Entities\Capability`. For example, a model with
`owner_id` and `workspace_id` can opt into both filters:

```php
Entity::make(Company::class)->capabilities(Capability::Ownable, Capability::WorkspaceScoped);
```

The compiled entity includes each capability once, including those added through convenience
methods such as `searchable()`.
