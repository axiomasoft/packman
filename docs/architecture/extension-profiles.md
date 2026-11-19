# Extension Profiles, Plugins & Modules

Packman explicitly separates **package architecture profiles**, **stateless intra-package code plugins (`axiomasoft/packman-plugins`)**, **DB-backed Composer-discovered platform modules (`axiomasoft/packman-modules`)**, and **composable `With*` extension facets (`Packman\Extensions`)**.

---

## 1. Architecture Profiles (`schemas/profiles/*.profile.json`)

Every package in `packman.json` declares one of the four architecture profiles validated by `ModuleLayoutGate`, `PhpApiGate`, `ProviderSizeGate`, and `PackageDocsGate`:

| Profile | `composer_type` | `allow_laravel_required` | `require_php_api_manifest` | `provider_max_lines` | `require_readme` / `require_changelog` | Intended Role |
|:--|:--|:--|:--|:--|:--|:--|
| **`php-library`** | `library` | `false` | `true` | `80` | `true` / `true` | Pure PHP 8.4+ libraries and the `axiomasoft/packman` kernel (`L1`). |
| **`laravel-package`** | `library` | `true` | `true` | `80` | `true` / `true` | Laravel packages, stateless plugins (`packman-plugins`), schema, panels, filament integration, and dev tools. |
| **`modular-platform`** | `library` | `true` | `true` | `80` | `true` / `true` | Runtime Composer-discovered module platforms (`packman-modules`). |
| **`product`** | `project` | `true` | `false` | `120` | `true` / `false` | Application composition roots. |

Scaffolding (`./bin/php84 bin/packman make <profile> <name>`) additionally exposes four stub templates in `Packman\DevTools\Scaffold\Layout`: `php-library`, `laravel-package`, `packman-plugin`, and `packman-module`.

---

## 2. Stateless Code Plugins (`packman-plugins`) vs. DB-Backed Modules (`packman-modules`)

| Dimension | Stateless Code Plugins (`axiomasoft/packman-plugins`) | DB-Backed Platform Modules (`axiomasoft/packman-modules`) |
|:--|:--|:--|
| **Package & Namespace** | `packages/plugins` -> `Packman\Plugins\` | `packages/modules` -> `Packman\Modules\` |
| **Database Requirement** | **Zero `illuminate/database` dependency.** Does not create or require `mod_*` tables. | Requires `illuminate/database` & `axiomasoft/packman-schema`; owns `mod_modules`, `mod_records`, and `mod_lifecycle_log`. |
| **Entry Contract** | `ExtensionPlugin` (`manifest(): PluginManifest`, `register(PluginRegistrar $registrar): void`), optional `RegistersNothing` trait | `ModuleDefinition` (`manifest(): ModuleManifest`) |
| **Discovery Source** | Explicit `ExtensionRegistrar::builtInFor($app, ...$plugins)` + config array `packman.plugins` | Composer `vendor/composer/installed.json` scanned by `ModuleDiscovery` (`ModulesOptions::$packageTypes` and `$extraKeys`) |
| **Ordering & Graph** | `PluginGraphCompiler` (Kahn's topological sort + deterministic `SORT_STRING` tie-break + transitive ancestor ownership) | `RegistryCompiler` (closed 8-stage pipeline `PipelineStages::ORDER` + topological menu/dependency checks) |
| **Compiled Artifact** | In-memory frozen `FrozenContributions` (`Contributions`, `PluginInspector`, `Capabilities`, `ExtensionRegistry`) | Atomic OPcache PHP artifact `bootstrap/cache/modules.php` read by `CompiledModuleRegistry` |
| **Activation Gating** | Code/config `$disabled` (`packman.disabled_plugins`); optional `PluginActivationGate` SPI for modular hosts | `DatabaseModuleLifecycle` (advisory lock, transactional state machine `ModuleState`, `EnableHook`/`DisableHook`/`PurgeHook`, `ModuleActivationGate`) |
| **Static Isolation** | Strict graph & interface validation in `PluginGraphCompiler` | 9 concrete AST PHPStan rules registered in `packages/modules/extension.neon` (`Packman\Modules\PHPStan\Rules\{Boundaries,Pooling}\*`) |

---

## 3. Stateless Plugins in Depth (`Packman\Plugins\`)

1. **`ExtensionPlugin` & `PluginManifest`:**
   - `ExtensionPlugin` constructors take zero arguments and execute zero I/O or container reads during registration.
   - `PluginManifest` validates `id` (`vendor.name` kebab-case pattern), strict SemVer `version`, `requires`, `optional`, `resourceRoot`, and supported `databases` (`pgsql`, `mysql`, `sqlite`).
2. **Capabilities & Typed Slots (`PluginRegistrar`):**
   - `defineCapability(string $contract, Cardinality $cardinality)` (`RequiredSingle`, `OptionalSingle`, `Many`)
   - `provides(string $contract, string $implementation)` and `providesFromConfig(string $contract, string $configKey, ?string $default = null)`
   - `defineSlot(string $contract, SlotKind $kind, Cardinality $cardinality)` (`Guard`, `Restriction`, `Contributor`, `Handler`, `EventSink`, `OutboxConsumer`, `OperationMiddleware`, `PipelineStage`)
   - `contributeSlot(string $slot, string $id, string $implementation, int $priority = 0, ?string $key = null)`
   - Legacy contribution kinds via `declareKind(ContributionKind)` and `contribute(string $kind, object|string $contribution)`
3. **Compilation & Immutability:**
   - `PluginGraphCompiler` seals `PluginDeclarations` immediately after `register()`, validates `$disabled` and `PluginActivationGate`, enforces transitive ancestor ownership (a plugin can only provide/contribute to a capability, slot, or kind owned by itself or a required ancestor — never via `optional` alone), and produces `FrozenContributions`.
   - Consumer services resolve `Contributions`, `PluginInspector`, `Capabilities` (`CapabilitiesRegistry`), and `ExtensionRegistry`.

---

## 4. DB-Backed Modules in Depth (`Packman\Modules\`)

1. **Configurable Composer Discovery (`ModulesOptions` & `ModuleDiscovery`):**
   - `ModulesOptions` defaults `package_types` to `['packman-module', 'packman-plugin', 'module', 'plugin']` and `extra_keys` to `[['packman', 'module'], ['packman', 'plugin'], ['module'], ['plugin']]`.
   - `ModuleDiscovery` scans `vendor/composer/installed.json` (or an injected `$fallbackModules` class list in tests), instantiates each `ModuleDefinition`, and hydrates `With*` facets via `ExtensionInspector`.
2. **Closed 8-Stage `RegistryCompiler` (`PipelineStages::ORDER`):**
   - Stage order: `Entity` -> `EntityField` -> `Permissions` -> `Api` -> `Filament` -> `Menu` -> `Routes` -> `Workflow`.
   - Also collects `settings`, `featureFlags`, and `listeners` onto `CompiledRegistry`, computing a deterministic SHA fingerprint over compiler identity, installed package versions, normalized extensions, and replacements.
3. **Warm Path & Lifecycle:**
   - `CompileModulesCommand` (`modules:compile`, configurable via `modules.compile_command`) writes `bootstrap/cache/modules.php` atomically via `CompiledModuleRegistry::writeAtomic()`.
   - `DatabaseModuleLifecycle` acquires `modules:lifecycle-graph` lock, enforces dependency graphs and `EnableHook`/`DisableHook`/`PurgeHook` contracts, and logs transitions to `mod_lifecycle_log` alongside `mod_modules` and `mod_records`.

---

## 5. Composable `With*` Contracts & Traits (`Packman\Extensions`)

Both `ExtensionPlugin` implementations and `ModuleDefinition` subclasses can compose any subset of the 10 contracts under `Packman\Extensions\Contracts` and their default traits under `Packman\Extensions\Concerns`, grouped into four domain subfolders:

| Domain Subfolder | Contract (`Contracts\<Domain>\`) | Default Trait (`Concerns\<Domain>\`) | Hydrated `ExtensionFacetProfile` Fields |
|:--|:--|:--|:--|
| **`Configuration`** | `WithConfiguration` | `Configurable` | `configRoot`, `defaultConfigPath`, `optionsClass`, `configSectionClass` |
| **`Configuration`** | `WithDatabaseSettings` | `InteractsWithDatabaseSettings` | `settingsNamespace`, `defaultSettings`, `sensitiveSettings` |
| **`Persistence`** | `WithDatabaseRegistration` | `RegistersInDatabase` | `persistsInDatabase`, `tracksOwnedRecords`, `defaultEdition` |
| **`Persistence`** | `WithSchema` | `OwnsSchema` | `tablePrefix`, `tableNouns`, `migrationPath`, `schemaSetProviders`, `schemaComponents` |
| **`Composition`** | `WithCapabilities` | `ProvidesCapabilities` | `definedCapabilities`, `providedCapabilities` |
| **`Composition`** | `WithSlots` | `ContributesToSlots` | `definedSlots`, `slotContributions` |
| **`Composition`** | `WithPanels` | `ProvidesPanels` | `panelProviders` |
| **`Runtime`** | `WithLifecycleHooks` | `HasLifecycleHooks` | `onEnableHook`, `onDisableHook`, `onPurgeHook`, `removalGuard` |
| **`Runtime`** | `WithDiagnostics` | `ProvidesDiagnostics` | `healthChecks`, `problemProviders` |
| **`Runtime`** | `WithFeatureFlags` | `DeclaresFeatureFlags` | `featureFlags` |

### Canonical Inspection & Hydration

- `ExtensionInspector::inspect(object $extension): ExtensionFacetProfile` extracts all facets in one pass.
- **`packman-plugins` bridge:** `PluginGraphCompiler::applyFacets()` automatically translates `WithCapabilities` and `WithSlots` declarations into `PluginRegistrar` calls; `ExtensionRegistrar::wirePluginFacets()` merges `WithConfiguration` defaults/singletons, declares `WithSchema::tableNouns()` onto `SchemaOwnership`, and tags `WithDiagnostics` `DoctorCheck` / `HealthCheck` classes.
- **`packman-modules` bridge:** `ModuleDiscovery::hydrateExtensionFacets()` hydrates `WithSchema::$tablePrefix`, `WithDatabaseSettings`, `WithFeatureFlags`, `WithLifecycleHooks`, and `WithPanels` directly onto `ModuleManifest`.
- **`packman-panels` bridge:** `PanelsBindingsRegistrar` reads `panelProviders` from both `PluginInspector` and `ModuleDiscovery` via `PanelExtensionBridge::registerProviders()`.
