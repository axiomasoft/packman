# Extension Profiles, Plugins & Modules

Packman explicitly separates **package architecture profiles**, **stateless intra-package code plugins (`axiomasoft/packman-plugins`)**, **compiled platform modules (`axiomasoft/packman-modules`)**, and **composable `With*` extension facets (`Packman\Extensions`)**.

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

## 2. Code plugins and compiled platform modules

| Dimension | Stateless Code Plugins (`axiomasoft/packman-plugins`) | Compiled Platform Modules (`axiomasoft/packman-modules`) |
|:--|:--|:--|
| **Package & Namespace** | `packages/plugins` -> `Packman\Plugins\` | `packages/modules` -> `Packman\Modules\` |
| **Database Requirement** | **Zero `illuminate/database` dependency.** Does not create or require `mod_*` tables. | The package depends on Laravel database components. Code-only compilation and host-supplied activation work without module tables. The default SQL lifecycle owns `mod_modules`, `mod_records`, and `mod_lifecycle_log`. |
| **Entry Contract** | `ExtensionPlugin` (`manifest(): PluginManifest`, `register(PluginRegistrar $registrar): void`), optional `RegistersNothing` trait | `ModuleDefinition` (`manifest(): ModuleManifest`) |
| **Discovery Source** | Explicit `ExtensionRegistrar::builtInFor($app, ...$plugins)` + config array `packman.plugins` | Composer metadata by default. Hosts can bind the public `ModuleDiscovery` contract. |
| **Ordering & Graph** | `PluginGraphCompiler` (Kahn's topological sort + deterministic `SORT_STRING` tie-break + transitive ancestor ownership) | `RegistryCompiler` (closed 8-stage pipeline `PipelineStages::ORDER` + topological menu/dependency checks) |
| **Compiled Artifact** | In-memory frozen `FrozenContributions` (`Contributions`, `PluginInspector`, `Capabilities`, `ExtensionRegistry`) | Atomic OPcache PHP artifact `bootstrap/cache/modules.php` read by `CompiledModuleRegistry` |
| **Activation Gating** | Explicit disabled IDs, the host gate and registered package gates must all permit activation. | Applications can bind `ModuleStateReader` for code or config activation. The default SQL lifecycle provides transactional transitions and enable, disable and purge hooks. |
| **Static Isolation** | Strict graph & interface validation in `PluginGraphCompiler` | 9 concrete AST PHPStan rules registered in `packages/modules/extension.neon` (`Packman\Modules\PHPStan\Rules\{Boundaries,Pooling}\*`) |

---

## 3. Stateless Plugins in Depth (`Packman\Plugins\`)

1. **`ExtensionPlugin` & `PluginManifest`:**
   - `ExtensionPlugin` constructors take zero required arguments and execute zero I/O or container reads during registration.
   - Packman discovers the manifest before evaluating activation policies. A container binding can provide the active instance of the same class with host-specific facet values and the same manifest.
   - `PluginManifest` validates `id` (`vendor.name` kebab-case pattern), strict SemVer `version`, `requires`, `optional`, `resourceRoot`, and supported `databases` (`pgsql`, `mysql`, `sqlite`).
2. **Capabilities & Typed Slots (`PluginRegistrar`):**
   - `defineCapability(string $contract, Cardinality $cardinality)` (`RequiredSingle`, `OptionalSingle`, `Many`)
   - `provides(string $contract, string $implementation)` and `providesFromConfig(string $contract, string $configKey, ?string $default = null)`
   - `defineSlot(string $contract, SlotKind $kind, Cardinality $cardinality)` (`Guard`, `Restriction`, `Contributor`, `Handler`, `EventSink`, `OutboxConsumer`, `OperationMiddleware`, `PipelineStage`)
   - `contributeSlot(string $slot, string $id, string $implementation, int $priority = 0, ?string $key = null)`
   - Legacy contribution kinds via `declareKind(ContributionKind)` and `contribute(string $kind, object|string $contribution)`
3. **Compilation & Immutability:**
   - `PluginGraphCompiler` checks `$disabled` and `PluginActivationGate`, seals active `PluginDeclarations` immediately after `register()`, enforces transitive ancestor ownership (a plugin can only provide/contribute to a capability, slot, or kind owned by itself or a required ancestor — never via `optional` alone), and produces `FrozenContributions`.
   - Consumer services resolve `Contributions`, `PluginInspector`, `Capabilities` (`CapabilitiesRegistry`), and `ExtensionRegistry`.

---

## 4. Module compilation and the SQL lifecycle (`Packman\Modules\`)

1. **Configurable Composer Discovery (`ModulesOptions` & `ModuleDiscovery`):**
   - `ModulesOptions` defaults `package_types` to `['packman-module', 'packman-plugin', 'module', 'plugin']` and `extra_keys` to `[['packman', 'module'], ['packman', 'plugin'], ['module'], ['plugin']]`.
   - `ModuleDiscovery` scans `vendor/composer/installed.json` (the host may supply another `ModuleDiscovery` implementation), instantiates each `ModuleDefinition`, and hydrates `With*` facets via `ExtensionInspector`.
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

## 6. Host lifecycle and product differences

| Moment | Package responsibility | Application responsibility | Resource boundary |
|:--|:--|:--|:--|
| Composer/Laravel discovery | Declare provider and manifest without resource access. | Install the desired function and use Laravel discovery. | No database connection or optional integration merely to discover metadata. |
| Registration | Offer classes, defaults, lazy bindings and gates on the supplied application. | Supply configuration, typed options or implementations before resolution. | No query, network or filesystem scanning in `register()`. |
| Activation | Apply disabled IDs and all applicable gates before active facets. Package gates permit unrelated extensions. | Select plugins or bind `PluginActivationGate`; choose module discovery/state contracts. | Disabled features cannot force resource configuration validation. |
| Active boot/compilation | Compile immutable options and declarations; report invalid active configuration. | Provide required declarations and host policies. | Active products may validate early: Chatom compiles runtime definitions; AzGuard may scan the explicitly selected panel catalogue. This is separate from SQL use. |
| Useful operation | Resolve resources required by the operation. | Supply its storage, connection, tenancy or optional integration. | Vaulter CAS writes need a transaction; Commerz allocation needs no SQL. |
| Migration | Declare sets, prerequisites and supported drivers. | Select a connection and explicitly execute migrations. | Automatic path loading does not run migrations. |

Keep registration state, options and compiled graphs on the owning application. Repeating
a known plugin is idempotent; introducing a new plugin after compilation is an error.
Product registries may be stricter: Tenant permits one runtime owner and rejects forced
replacement; AzGuard requires explicit `replace()` for an existing panel ID.

The global disabled-plugin list is not a package uninstaller. Tenant descriptors are not
`ExtensionPlugin` objects. Sockit feature providers require explicit host opt-in. Vaulter
retains schemas for installed inactive modules. Preserve these working distinctions.

Corex access is installed as a finished module. Application code uses Corex
`AccessChecker` and role/scope APIs; it does not select an engine package or construct
AzGuard panel/subject adapters.
