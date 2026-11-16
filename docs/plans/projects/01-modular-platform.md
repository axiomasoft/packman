# Project Migration Spec 01: `modular-platform` (Reference Platform Consumer)

**Repository Path:** `/home/vostrikov/projects/packages/<platform-repo>`  
**Target Profile:** `modular-platform`  
**Core Rule:** `packman` contains **zero references to downstream host products**. The reference modular platform adapts and extracts its `Modules` (111 files), `Attributes`, `Config`, `Errors`, `Database/SchemaSets`, `Data/JsonDocuments`, `Context` contracts, `panels` (34 files), and `scripts/*` gates into `axiomasoft/packman` (`Packman\*`) + `packman-{modules,schema,panels,testkit,devtools}`, and consumes them downstream while preserving full SemVer compatibility for legacy host consumers.

---

## 1. Exact Adaptation of `<platform>/packages/core/src/Modules` (111 Files) into `Packman\Modules`

### 1.1. Complete Inventory of the Files Moved & Adapted to `packman/packages/modules/`
- **Root (2):** `src/ModulesServiceProvider.php`, `extension.neon` (from `<platform>/packages/core/extension.neon`, renamed parameter `<host>Modules` -> `packmanModules`, registering only the 9 `Packman\Modules\PHPStan\Rules\*` rules while host messaging rules stay in the platform repo)
- **Migrations (3):** `database/migrations/tenant/{2026_10_01_010010_create_mod_modules,2026_10_01_010020_create_mod_records,2026_10_01_010030_create_mod_lifecycle_log}.php` (registered by `Internal/ModulesSchemaSets.php` via `Packman\Schema`)
- **Contracts (19):** `DisableHook.php`, `EnableHook.php`, `EntityRegistry.php`, `Extender.php`, `FieldCastRegistry.php`, `ManifestSectionCompiler.php`, `ModuleActivationGate.php`, `ModuleCompilation.php`, `ModuleDefinition.php`, `ModuleDiscovery.php`, `ModuleLifecycle.php`, `ModuleRegistrar.php`, `ModuleRegistry.php`, `ModuleRegistryCache.php`, `ModuleStateReader.php`, `PackageRegistrar.php` (moved to `Packman\Foundation\Contracts\PackageRegistrar`), `PurgeHook.php`, `RecordsRegistrar.php`, `RegistryIntrospection.php`
- **Data (18):** `Api.php`, `CompiledEntityField.php`, `CompiledModule.php`, `CompiledRegistry.php`, `Entity.php`, `EntityDefinition.php`, `EntityField.php`, `ExtensionReplacement.php`, `ExtensionStamp.php`, `FieldDefinition.php`, `InstalledPackageVersion.php`, `ModuleExplanation.php`, `ModuleManifest.php`, `ModuleRuntimeContext.php`, `ModuleStatus.php`, `NormalizedExtension.php`, `Permissions.php`, `RegistryMap.php`
- **Enums (3):** `Capability.php`, `DisableReason.php`, `ModuleState.php`
- **Events (5):** `ModuleArchived.php`, `ModuleDisabled.php`, `ModuleEnabled.php`, `ModulePurged.php`, `ModuleUpgraded.php`
- **Exceptions (13):** `CompilationException.php`, `EntityNotFound.php`, `HasEnabledDependentsException.php`, `InvalidManifestException.php`, `InvalidRegistryPoint.php`, `InvalidStateTransitionException.php`, `MissingDependencyException.php`, `ModuleEnableFailedException.php`, `ModuleNotFound.php`, `ModuleUpgradeFailedException.php`, `RegistryFrozen.php`, `RegistryInputUnavailable.php`, `StaleRegistryRevision.php`
- **Internal (28):** `InMemoryEntityRegistry.php`, `ReadRegistryIntrospection.php`, `ServiceProviderModuleRegistrar.php`, `Audit/AuditCapabilityWiring.php` (extracted to host audit hook), `Commands/{CompileModulesCommand,ExplainModuleCommand,RepairModuleCommand}.php`, `Compiler/{CompilationValidator,CompiledSettingDefault,FieldCastMap,InstalledPackages,PipelineStages,RegistryCompiler}.php`, `Definition/ModuleDependency.php`, `Discovery/ModuleDiscovery.php`, `Events/ManifestListeners.php`, `Extend/{Filament,Menu,Routes,Workflow}.php`, `Gate/{AllowAllActivationGate,ModuleActivationDecision}.php`, `Lifecycle/{DatabaseModuleLifecycle,DatabaseModuleStateReader,DatabaseRecordsRegistrar,ModuleMigrationClaims}.php`, `Registry/{ArrayModuleStateReader,CompiledModuleRegistry,RepositoryModuleRegistryCache}.php`, `Settings/{CompiledSettingDefaultsProvider,DefaultSettingDefinition}.php`, `Support/{ModulesConfigSection,ModulesOptions}.php`
- **Laravel (6):** `HostModulesRegistrar.php` (renamed `ModulesRegistrar.php`), `LifecycleRegistrar.php`, `ModulesBootRegistrar.php`, `RegistersNothingOnBoot.php` (moved to `Packman\Foundation\Concerns`), `RegistryRegistrar.php`, `SettingsRegistrar.php`
- **PHPStan/Rules (11):** `AbstractDatabaseCallRule.php`, `NoDirectSystemTableWriteRule.php`, `NoEvalRule.php`, `NoForeignPrefixSqlRule.php`, `NoFsOutsideModuleRule.php`, `NoListenNotifyRule.php`, `NoSessionAdvisoryLockRule.php`, `NoSetWithoutLocalRule.php`, `NoSqlPrepareRule.php`, `NoTempTableRule.php`, `Support/DatabaseCall.php`
- **Testing (2):** `ArrayEntityRegistry.php`, `ExtensionContract.php`

### 1.2. All 29 Non-`Modules` Host Imports Replaced in `Packman\Modules`

| # | Original Host Import | Files in `Modules` | Universal `Packman\*` Replacement | How Host Platform Wires Its Implementation |
|:--|:--|:--|:--|:--|
| 1 | `<Host>\Attributes\Api` | 24 files | `Packman\Attributes\Api` | Host `Attributes\Api` becomes class alias/re-export |
| 2 | `<Host>\Attributes\Spi` | 54 files | `Packman\Attributes\Spi` | Host `Attributes\Spi` becomes class alias/re-export |
| 3 | `<Host>\Attributes\SpiKind` | 54 files | `Packman\Attributes\SpiKind` | Host `Attributes\SpiKind` becomes enum alias/re-export |
| 4 | `<Host>\Audit\Contracts\CurrentActor` | `DatabaseModuleLifecycle.php:7` | `Packman\Context\Contracts\ActorResolverInterface` (returns `ActorRef($type, $id)`) | Host actor resolver adapts `CurrentActor` |
| 5 | `<Host>\Audit\Laravel\AuditRegistration` | `AuditCapabilityWiring.php:7` | Removed from `Modules`; replaced by post-entity-registry wiring hook | Stays in host repo (`Audit\Laravel\AuditCapabilityWiring`) observing `EntityRegistry` |
| 6 | `<Host>\Audit\Laravel\HasAuditLog` | `AuditCapabilityWiring.php:8` | Stays in host repo inside `AuditCapabilityWiring` | Executed by host audit registrar |
| 7 | `<Host>\Config\Contracts\ConfigReportSection` | `ModulesConfigSection.php:7`, `ModulesServiceProvider.php:9` | `Packman\Config\Contracts\ConfigReportSectionInterface` | Host config report reads tagged `ConfigReportSectionInterface` |
| 8 | `<Host>\Config\Internal\ApplicationLocator` | `HostModulesRegistrar.php:9` | `Packman\Foundation\ApplicationLocator` | Direct replacement |
| 9 | `<Host>\Config\Laravel\ConfigurationValidator` | `ModulesOptions.php:7` | `Packman\Config\Laravel\ConfigurationValidator` | Direct replacement |
| 10 | `<Host>\Context\Contracts\TenantContextResolver` | `ManifestListeners.php:7`, `DatabaseModuleLifecycle.php:8`, `LifecycleRegistrar.php:9` | `Packman\Context\Contracts\ContextResolverInterface` | Host `TenantContextResolver` implements `ContextResolverInterface` |
| 11 | `<Host>\Context\Data\AccountRef` | `ExplainModuleCommand.php:7` | `Packman\Context\Data\ScopeRef` | `AccountRef` maps to `ScopeRef` |
| 12 | `<Host>\Context\Data\TenantContext` | 15 files (`Contracts/*`, `Internal/*`, `Data/*`) | `Packman\Context\Contracts\ContextScopeInterface` (`id(): string`) | Host `TenantContext` implements `ContextScopeInterface` |
| 13 | `<Host>\Context\Exceptions\CentralContextException` | `ManifestListeners.php:8` | `Packman\Context\Exceptions\ScopeUnavailableException` | `CentralContextException` extends `ScopeUnavailableException` |
| 14 | `<Host>\Data\Data\JsonDocument` | `FieldDefinition.php:9` | `Packman\Data\Data\JsonDocument` | Alias/re-export in host repo |
| 15 | `<Host>\Data\Laravel\JsonDocuments` | `CompiledRegistry.php:10`, `FieldDefinition.php:10`, `RegistryCompiler.php:7` | `Packman\Data\Laravel\JsonDocuments` | Alias/re-export in host repo |
| 16 | `<Host>\Database\Contracts\SchemaSets` | `LifecycleRegistrar.php:10` | `Packman\Schema\Contracts\SchemaSets` | Direct replacement |
| 17 | `<Host>\Database\Enums\SchemaLifecycle` | `LifecycleRegistrar.php:11` | `Packman\Schema\Enums\SchemaLifecycle` | Direct replacement |
| 18 | `<Host>\Database\Enums\SchemaScope` | `LifecycleRegistrar.php:12` | `Packman\Schema\Enums\SchemaScope` | Direct replacement |
| 19 | `<Host>\Database\Exceptions\SchemaSetException` | `LifecycleRegistrar.php:13` | `Packman\Schema\Exceptions\SchemaSetException` | Direct replacement |
| 20 | `<Host>\Errors\Contracts\DescribesError` | `InvalidStateTransitionException.php:8` | `Packman\Errors\Contracts\DescribesError` | Direct replacement |
| 21 | `<Host>\Errors\Data\ErrorDescriptor` | All 13 exception files | `Packman\Errors\Data\ErrorDescriptor` | Direct replacement |
| 22 | `<Host>\Errors\Enums\CoreErrorCode` | All 13 exception files | `Packman\Modules\Enums\ModuleErrorCode` (implements `Packman\Errors\Contracts\ErrorCode`) | Registered in host problem definitions |
| 23 | `<Host>\Errors\Enums\ErrorCategory` | All 13 exception files | `Packman\Errors\Enums\ErrorCategory` | Direct replacement |
| 24 | `<Host>\Errors\Exceptions\CodedException` | 12 exception files | `Packman\Errors\Exceptions\CodedException` | Direct replacement |
| 25 | `<Host>\Features\Contracts\FeatureFlags` | `DatabaseModuleLifecycle.php:10` | `Packman\Features\Contracts\FeatureFlagsInterface` | Host `FeatureFlags` extends `FeatureFlagsInterface` |
| 26 | `<Host>\Features\Data\FeatureFlagDefinition` | `CompiledRegistry.php:11`, `ModuleManifest.php:9`, `RegistryCompiler.php:8` | `Packman\Features\Data\FeatureFlagDefinition` | Alias/re-export in host repo |
| 27 | `<Host>\Messaging\Events\DomainEvent` | 5 event files (`Events/Module*.php`) | `Packman\Events\DomainEvent` | Host `DomainEvent` extends `Packman\Events\DomainEvent` |
| 28 | `<Host>\Messaging\Contracts\PubSub` | `NoListenNotifyRule.php:12` (docblock) | Docblock updated to generic event transport interface | N/A (docblock only) |
| 29 | `<Host>\Settings\Contracts\SettingDefaultsProvider` | `CompiledSettingDefaultsProvider.php:9`, `SettingsRegistrar.php:12` | `Packman\Settings\Contracts\SettingDefaultsProviderInterface` | Host `SettingDefaultsProvider` extends it |

### 1.3. All 13 Hardcoded Strings Replaced with Configurable Options in `ModulesOptions`

| Hardcoded String in Host `Modules` | Source Location | Universal Default in `Packman\Modules` | Configuration in Modular Platform |
|:--|:--|:--|:--|
| `'<host>-module'` | `ModuleDiscovery.php:70`, `LifecycleRegistrar.php:98` | `ModulesOptions::$packageTypes = ['packman-module', 'packman-plugin', 'module', 'plugin']` | Host config sets `['<host>-module', '<host>-plugin', 'module', 'plugin']` |
| `extra['<host>']['module']` | `ModuleDiscovery.php:75,78` | `ModulesOptions::$extraKeys = [['packman', 'module'], ['packman', 'plugin'], ['module'], ['plugin']]` | Host config includes `['<host>', 'module']` and `['<host>', 'plugin']` |
| `'<host>:modules:compile'` | `CompileModulesCommand.php:28`, `ModulesServiceProvider.php:40` | `'modules:compile'` | Host aliases `'<host>:modules:compile'` |
| `'<host>:modules:why'` | `ExplainModuleCommand.php:17` | `'modules:why'` | Host aliases `'<host>:modules:why'` |
| `'<host>:modules:repair'` | `RepairModuleCommand.php:18`, `DatabaseModuleLifecycle.php:137` | `'modules:repair'` | Host aliases `'<host>:modules:repair'` |
| `'<host>.modules'` | `ModulesConfigSection.php:19`, `ModulesOptions.php:29`, `RegistryRegistrar.php:45` | `'modules'` (`config('modules', [])`) | Host config wires `<host>.modules` |
| `'<host>.modules.wiring'` | `LifecycleRegistrar.php:55,119` | `ModuleWiring::class` / `'modules.wiring'` | Container alias if needed |
| `'<host>:module-lifecycle-graph'` | `DatabaseModuleLifecycle.php:388` | `ModulesOptions::$lockKey = 'modules:lifecycle-graph'` | Config preserves or migrates key |
| `'bootstrap/cache/<host>_modules.php'` | `CompiledModuleRegistry.php:71` | `ModulesOptions::$cachePath = 'bootstrap/cache/modules.php'` | Host sets custom cache path for BC |
| `'<host>-registry/1'` | `CompiledRegistry.php:34` | `'packman-registry/1'` | Format version in `CompiledRegistry` |
| `'axiomasoft/<host>'` | `RegistryCompiler.php:1123` | `InstalledPackages` resolves `$compilerPackage = 'axiomasoft/packman-modules'` | Configured via compiler options |
| `'mod_modules'`, `'mod_records'`, `'mod_lifecycle_log'` | `DatabaseModuleLifecycle.php:71,73,75`, `DatabaseModuleStateReader.php:33`, `DatabaseRecordsRegistrar.php:36,59` | Configurable table names in `ModulesOptions` defaulting to `'mod_modules'`, `'mod_records'`, `'mod_lifecycle_log'` | 100% DB schema compatible |
| `'<host>Modules.*'` | All 10 `PHPStan/Rules/*.php` identifiers | `'modules.*'` | `phpstan.neon` rule identifiers updated |

---

## 2. Partitioning `<platform>/packages/core/src/*` Outside `Modules` & `packages/panels`

- **Move to `packman` (host platform consumes via `require` + optional `class_alias` BC shims):**
  - `Attributes/{Api,Spi,SpiKind}` -> `axiomasoft/packman` (`Packman\Attributes\*`)
  - `Attributes/Tooling/*` -> `axiomasoft/packman-devtools` (`require-dev`)
  - `Config/{Contracts/*,Data/SecretRef,Exceptions/InvalidConfiguration,Internal/ApplicationLocator,Laravel/{ConfigurationValidator,ConfiguredImplementation,RuntimeConfigGate}}` -> `axiomasoft/packman` (`Packman\Config\*` & `Packman\Foundation\*`)
  - `Errors/{Contracts,Data,Enums/{ErrorCategory,UnavailabilityReason},Exceptions/CodedException,Internal/*,Laravel/*}` -> `axiomasoft/packman` (`Packman\Errors\*`); `Tooling/ErrorSourceScanner` -> `axiomasoft/packman-devtools`
  - `Database/{Contracts,Data,Enums,Exceptions,Internal/{DefaultSchemaMigrator,TaggedSchemaSets},Commands/*}` -> `axiomasoft/packman-schema`; `Testing/MigratesSchemaSets` -> `axiomasoft/packman-testkit`
  - `Data/{Data/JsonDocument,Laravel/{JsonDocuments,JsonDocumentCast}}` -> `axiomasoft/packman` (`Packman\Data\*`)
  - `packages/panels/src/{Contracts,Data,Internal/{ModulePanelCatalogue,EntityViewSchemaProvider,PanelsOptions},Laravel/*,SavedViews/*}` -> `axiomasoft/packman-panels` (while `PanelsModule.php`, `PanelsSchemaSets.php`, and `database/migrations/tenant/*` stay in host `packages/panels`)
- **Stay in host platform:**
  - Domain & platform engines: `Audit` (including `AuditCapabilityWiring`), `Departments`, `Features` (DB implementation), `Invocation`, `Messaging` (Outbox/PubSub), `Observability`, `Scheduling`, `Settings` (DB repository), `Storage`, `Time`, `Context` (full `TenantContext`, `ImpersonationService`, `TenancyManager`), `CoreOptions`, `CoreErrorCode`, `CoreSchemaSets`.

---

## 3. File-by-File Changes Across All 21 Platform Subpackages

| # | Package | Exact File-by-File Changes |
|:--|:--|:--|
| 1 | `packages/core` | Require `axiomasoft/packman`, `packman-modules`, `packman-schema`; remove `src/Modules/*` (keep thin BC namespace aliases if required by SemVer); convert `src/Attributes/*`, `src/Database/{Contracts,Data,Enums,Internal/*}`, `src/Errors/{Contracts,Data,Exceptions/CodedException}`, `src/Data/{JsonDocument,JsonDocuments}` to `Packman\*` imports/aliases; `CoreServiceProvider` registers `Packman\Modules\ModulesServiceProvider` and keeps `AuditCapabilityWiring`. |
| 2 | `packages/panels` | Require `axiomasoft/packman-panels`, `packman-modules`, `packman-schema`; keep `src/PanelsModule.php`, `src/Internal/PanelsSchemaSets.php`, migrations; delegate panel contracts, `ModulePanelCatalogue`, and `SavedViews` to `Packman\Panels\*`. |
| 3 | `packages/access` | Update imports in `CoreAuthServiceProvider`, `PermissionsMerger`, `ActiveEntityFilter`, `ScopeRegistry`, `AccessSchemaSets`, and 3 `Laravel/*Registrar.php` to `Packman\Modules\*`, `Packman\Schema\*`, `Packman\Foundation\Contracts\PackageRegistrar`. |
| 4 | `packages/access-azguard` | Fix `"axioma-studio/azguard-core"` -> `"axiomasoft/azguard"`; update `AccessAzguardRegistrar`, `TenantContextGuard`, `AccessAzguardSchemaSets` to `Packman\*`. |
| 5 | `packages/custom-fields` | Update `AttributesServiceProvider`, `ModuleFieldCatalogue`, `FieldCatalogueFactory`, `CustomFieldsSchemaSets`, `JsonDocument` references to `Packman\*`. |
| 6 | `packages/intl` | `IntlModule` extends `Packman\Modules\Contracts\ModuleDefinition`; `IntlServiceProvider` injects `Packman\Modules\Contracts\FieldCastRegistry`; `IntlSchemaSets` uses `Packman\Schema`. |
| 7 | `packages/media` | `MediaModule`, `MediaServiceProvider`, `MediaBindingsRegistrar`, `MediaSchemaSets` updated to `Packman\*`. |
| 8 | `packages/notifications` | `NotificationsModule`, `NotificationsServiceProvider`, `NotificationsSchemaSets`, `Payloads/*` (`JsonDocuments`) updated to `Packman\*`. |
| 9 | `packages/operations` | `ApiServiceProvider`, 6 registrars, `ApiProfileFactory`, `ApiProfiles`, `OperationsSchemaSets`, `Exceptions/*` updated to `Packman\*`. |
| 10 | `packages/recurrence` | `Exceptions/*` -> `Packman\Errors\Exceptions\CodedException`; `Rule/*` -> `Packman\Attributes\Api`. |
| 11 | `packages/search` | `SearchServiceProvider`, `SearchSchemaSets`, `Indexing/*` (`JsonDocuments`) updated to `Packman\*`. |
| 12 | `packages/search-intl` | `SearchIntlServiceProvider`, `Normalizers/*` updated to `Packman\*`. |
| 13 | `packages/search-meilisearch` | `MeilisearchServiceProvider`, `Exceptions/*` updated to `Packman\*`. |
| 14 | `packages/tasks` | `TasksModule`, `TasksServiceProvider`, `TaskModuleGuard`, `TasksSchemaSets` updated to `Packman\*`. |
| 15 | `packages/tasks-workflow` | `TaskWorkflowIntegration`, `TasksWorkflowServiceProvider`, `TasksWorkflowSchemaSets` updated to `Packman\*`. |
| 16 | `packages/tenancy` | `TenancyServiceProvider`, 8 registrars, `TenantContext` (`ContextScopeInterface`), `TenantContextResolver` (`ContextResolverInterface`), `TenancySchemaSets` updated to `Packman\*`. |
| 17 | `packages/tools` | `ToolServiceProvider`, `ToolSectionCompiler` (`ManifestSectionCompiler`), `Exceptions/*` (`CompilationException`) updated to `Packman\*`. |
| 18 | `packages/transfer` | `TransferServiceProvider`, `TransferSchemaSets`, `Engines/*` (`JsonDocument`) updated to `Packman\*`. |
| 19 | `packages/wakeup` | `WakeupModule`, `WakeupServiceProvider`, `WakeupHandlerRegistry` (`ExtensionStamp`), `WakeupBindingsRegistrar`, `WakeupSchemaSets` updated to `Packman\*`. |
| 20 | `packages/webhooks` | `WebhookHttpRegistrar`, `WebhookBindingsRegistrar`, `WebhooksSchemaSets`, `Signers/*`, `Payloads/*` updated to `Packman\*`. |
| 21 | `packages/workflow` | `WorkflowModule`, `WorkflowServiceProvider`, `SqlWorkflowBinding`, `WorkflowDefinitionStamp`, `WorkflowBindingFactory`, `WorkflowSchemaSets` updated to `Packman\*`. |

---

## 4. Root Tooling & AI Environment Alignment in Modular Platform

- Replace duplicated gate scripts in `scripts/*-gate.php`, `scripts/php-api.php`, `scripts/error-registry.php`, `scripts/quality/rector/*` with `axiomasoft/packman-devtools` driven by a root `packman.json` (`profile: "modular-platform"`).
- Fix broken `.codex/agents/*.toml` (`skills = [...]` -> valid `BundledSkillsConfig`, remove unknown `command_requirements`) via `packman ai:sync`.
- Verify all existing SQLite (`composer test`), PostgreSQL (`composer test:pg`), architecture gates, and `resources/contracts/php-api.json` snapshots stay 100% green.
