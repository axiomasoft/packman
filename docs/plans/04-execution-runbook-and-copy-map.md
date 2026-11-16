# 04. Execution Runbook & Exact Copy-and-Refactor Map

**Purpose:** Prevent any AI executor from rewriting battle-tested code from scratch. Every subsystem in `packman` MUST follow the **"Copy-First, Surgical-Refactor-Second"** methodology: copy the source implementation and its existing tests directly from the audited sibling repository, then apply minimal surgical edits to generalize namespaces (`Packman\*`), eliminate hardcoded `corex` strings, and wire into the unified 8-package structure.

---

## 1. Execution Discipline ("Copy-First, Surgical-Refactor-Second")

1. **Never rewrite Opus's `Modules` loader or proven algorithms from memory.** Copy exact `.php`, migration, `.neon`, and test files first, preserve algorithms/invariants line-for-line, then surgically update imports/namespaces and configurable options.
2. **Every subsystem brings its existing unit, feature, concurrency, and fixture tests.** No code moves into `packman` without its tests passing on both SQLite and PostgreSQL lanes.
3. **Zero `corex` Strings Inside `packman`:** Immediately after copying files from `corex`, run `grep -rni "corex" packages/` inside `packman` — the final count inside `packages/` MUST be **0**.
4. **Fresh-Context Verification:** Every implementation wave closes with Pint (`lint:check`), PHPStan max (`analyse`), PHPUnit/Pest (`test`, `test:pg`), and an independent read-only audit via `codex -a never exec --sandbox read-only --model gpt-6-astra`.

---

## 2. Complete Copy-and-Refactor Map (Package by Package)

### Package 1: `packages/core` (`axiomasoft/packman` — `Packman\`)

| Target Location in `packman/packages/core` | Exact Source Files to Copy | Required Surgical Edits | Existing Tests to Copy & Adapt |
|:--|:--|:--|:--|
| `src/Attributes/` | `corex/packages/core/src/Attributes/{Api,Spi,SpiKind}.php`<br>`vaulter/packages/core/src/Attributes/Internal.php` | Namespace `Packman\Attributes`; standardize constructors & `since` defaults. | `corex/packages/core/tests/Attributes/AttributesTest.php` |
| `src/Foundation/` | `corex/packages/core/src/Modules/Contracts/PackageRegistrar.php`<br>`corex/packages/core/src/Modules/Laravel/RegistersNothingOnBoot.php`<br>`corex/packages/core/src/Config/Internal/ApplicationLocator.php` | Namespace `Packman\Foundation\*`; add `Concerns/RegistersPackageRegistrars.php` extracting the `CoreServiceProvider::REGISTRARS` loop pattern. | `corex/packages/core/tests/Unit/PackageRegistrarTest.php` |
| `src/Context/` | New universal interfaces inspired by `corex/packages/core/src/Context`: `ContextScopeInterface`, `ContextResolverInterface`, `ActorResolverInterface`, `ScopeRef`, `ActorRef`, `ScopeUnavailableException` | Keep 100% pure PHP (zero Eloquent/tenancy DB dependencies). | New unit tests in `tests/Unit/Context/` |
| `src/Data/` | `corex/packages/core/src/Data/{Data/JsonDocument,Laravel/JsonDocuments,Laravel/JsonDocumentCast}.php` | Namespace `Packman\Data\*`. | `corex/packages/core/tests/Unit/JsonDocumentBoundaryTest.php` |
| `src/Config/` | `corex/packages/core/src/Config/{Contracts/*,Data/SecretRef,Exceptions/InvalidConfiguration,Laravel/{ConfigurationValidator,ConfiguredImplementation,RuntimeConfigGate},Internal/Commands/ValidateConfigCommand}.php`<br>`commerz/packages/core/src/Configuration/CommerzConfig.php` (pattern for `OptionsFactory`)<br>`chatom/packages/core/src/Configuration/Contracts/{ConfigReader,ConfigSection,SettingsLookup}.php`<br>`chatom/packages/core/src/Configuration/Data/CompileContext.php`<br>`chatom/packages/core/src/Configuration/Internal/Services/{CompileJournal,CompiledSections,DefinitionsCache,DefinitionsCodec,JournalingConfigReader,SectionCompiler,SectionRegistrar}.php`<br>`azguard/packages/core/src/Configuration/ConfigSections.php`<br>`vaulter/packages/core/src/Configuration/ConfigNormalizer.php` | Namespace `Packman\Config\*`; unify `InvalidConfiguration`, `JournalingConfigReader`, `SectionCompiler` (Levenshtein), `DefinitionsCache` (round-trip check), `ConfigSections::own()`, `REPLACEMENT_MAPS`; make `chatom` prefix strings configurable. | `corex/packages/core/tests/Unit/CoreOptionsTest.php`<br>`corex/packages/core/tests/Feature/ValidateConfigCommandTest.php`<br>`chatom/tests/Unit/Configuration/{DefinitionReaders,DefinitionsCache,DefinitionsCodec,SectionCompiler}Test.php`<br>`chatom/tests/Feature/Configuration/{ChatomConfig,ProductionGuard}Test.php` |
| `src/Errors/` | `corex/packages/core/src/Errors/{Contracts,Data,Enums,Exceptions,Internal,Laravel}/*.php` (except `Tooling/ErrorSourceScanner.php`) | Namespace `Packman\Errors\*`; rename `CoreErrorCode` entries to universal `packman.*` codes. | `corex/packages/core/tests/Unit/ErrorDescriptorTest.php`<br>`corex/packages/core/tests/Feature/ProblemRegistryTest.php` |
| `src/Doctor/` | `chatom/packages/core/src/Diagnostics/{Contracts,Data,Enums,Internal/Services/DoctorReportBuilder}.php`<br>`vaulter/packages/core/src/Doctor/{DoctorRegistry,Checks/ConfigValidCheck,Checks/SchemaTablesCheck}.php`<br>`commerz/packages/core/src/Internal/Console/DoctorCommand.php` | Namespace `Packman\Doctor\*`; keep `chatom`'s `HealthCheck` / `DoctorFinding` / cascade DB skip + `vaulter`'s segment-exact prefix matching + `commerz`'s 2-column CLI output. | `chatom/tests/Feature/Diagnostics/DoctorCommandTest.php`<br>`vaulter/packages/core/tests/Doctor/{DoctorJsonSchema,DoctorRunner}Test.php` |
| `src/Events/`, `src/Features/`, `src/Settings/` | `corex/packages/core/src/Messaging/Events/DomainEvent.php`<br>`corex/packages/core/src/Features/{Data/FeatureFlagDefinition,Contracts/FeatureFlags}.php`<br>`corex/packages/core/src/Settings/Contracts/SettingDefaultsProvider.php` | Pure PHP base `DomainEvent`, `FeatureFlagDefinition`, `FeatureFlagsInterface`, `SettingDefaultsProviderInterface`. | Unit contract tests |

---

### Package 2: `packages/modules` (`axiomasoft/packman-modules` — `Packman\Modules\`)

> **STRICT PRESERVATION RULE:** Copy all 111 PHP files, 3 database migrations, `extension.neon`, and existing tests directly from `corex`. **Do NOT alter Opus's compiler pipeline, topological sort, lifecycle state machine, or `mod_records` claims logic.**

| Target Location in `packman/packages/modules` | Exact Source Files to Copy | Required Surgical Edits |
|:--|:--|:--|
| `src/**` (110 files; `PackageRegistrar` & `RegistersNothingOnBoot` move to `packages/core`) | `corex/packages/core/src/Modules/**` (111 files minus 2 Foundation files, plus `ModulesSchemaSets.php` & `ModulesMigrationBoundary.php`) | 1. Replace `CoreX\Modules\` namespace -> `Packman\Modules\`.<br>2. Replace the 29 non-`Modules` `CoreX\*` imports with `Packman\*` kernel contracts & `Packman\Schema\*` (per [01-corex.md](projects/01-corex.md)).<br>3. Extract `AuditCapabilityWiring` to an optional post-registry listener hook (no `AuditRegistration` dependency).<br>4. Move the 13 hardcoded `corex*` strings (`packageTypes`, `extraKeys`, `configRoot`, `cachePath`, `cachePrefix`, `lockKey`, `compilerPackage`, command names, PHPStan IDs) into `ModulesOptions` with universal defaults (`['packman-module', 'packman-plugin', 'module', 'plugin']`, `[['packman', 'module'], ['packman', 'plugin'], ['module'], ['plugin']]`, `'modules'`, `'bootstrap/cache/modules.php'`, `'modules:compile'`, etc.) for Bitrix/WordPress-style DB-tracked modules/plugins.<br>5. Add `Internal/ModulesSchemaSets.php` registering `database/migrations/tenant` (`mod_modules`, `mod_records`, `mod_lifecycle_log`). |
| `extension.neon` | `corex/packages/core/extension.neon` | Rename `parameters.corexModules` -> `parameters.packmanModules`; reference `Packman\Modules\PHPStan\Rules\*`; keep only the 9 module rules (`Messaging` rules stay in `corex`). |
| `database/migrations/tenant/` | `corex/packages/core/database/migrations/tenant/2026_10_01_010010_create_mod_modules.php`<br>`..._010020_create_mod_records.php`<br>`..._010030_create_mod_lifecycle_log.php` | Preserve exact DDL (`mod_modules_pk`, constraints, `mod_records`, `mod_lifecycle_log`) so existing schemas stay 100% compatible. |
| `tests/` | `corex/packages/core/tests/Modules/{Feature,Unit}/*` (10 files)<br>`corex/packages/core/tests/Support/Stubs/Lifecycle/*` (8 files)<br>`corex/packages/core/tests/Support/{ConfigReportTestSection,Modules/ConfigReportSectionTest}.php`<br>`corex/tests/Feature/{ModuleDiscovery,ModuleLifecycle,ModuleRegistry}Test.php`<br>`corex/tests/PHPStan/ModuleBoundaryRulesTest.php` + `data/fs-outside-module.php`<br>`corex/tests/Stubs/{StubModuleDefinition.php,Lifecycle/*,installed-with-corex-core.json}` | Update namespaces to `Packman\Modules\*` and rename `installed-with-corex-core.json` -> `installed-with-packman-core.json` (plus add fixture test verifying custom `packageTypes` + `extraKeys` override works!). |

---

### Package 3: `packages/plugins` (`axiomasoft/packman-plugins` — `Packman\Plugins\`)

| Target Location in `packman/packages/plugins` | Exact Source Files to Copy | Required Surgical Edits | Existing Tests to Copy & Adapt |
|:--|:--|:--|:--|
| `src/Contracts/`, `Data/`, `Internal/`, `Laravel/`, `Testing/` | `commerz/packages/core/src/Plugins/**` & `PluginGraph.php`<br>`vaulter/packages/core/src/Modules/{Cardinality,SlotKind,CapabilityDefinition,CapabilityProvider,SlotDefinition,SlotContribution,ExtensionRegistry}.php`<br>`chatom/packages/core/src/Plugins/{PluginManifest,Internal/ContributionChecks}.php` | Unify `ExtensionPlugin` + `PluginManifest` (`vendor.name`, semver, `requires`, `optional`, optional `resourceRoot`, `databases`), `CapabilityDefinition`/`SlotDefinition` (`Cardinality`, `SlotKind`, `providesFromConfig`), Kahn's topological sort + ancestor ownership enforcement (`PluginGraphCompiler`), `FrozenContributions`, and `ExtensionRegistry`. | `commerz/packages/core/tests/Feature/PluginsTest.php`<br>`commerz/packages/testkit/tests/Feature/PluginContractTestsTest.php`<br>`vaulter/packages/core/tests/Modules/{Capabilities,ExtensionRegistry,ExtensionSeamsContract,Seal}Test.php`<br>`chatom/tests/Unit/Plugins/{ContributionIdentityCompiler,PluginGraphCompiler}Test.php`<br>`chatom/tests/Integration/Plugins/GraphDeterminismTest.php` |

---

### Package 4: `packages/schema` (`axiomasoft/packman-schema` — `Packman\Schema\`)

| Target Location in `packman/packages/schema` | Exact Source Files to Copy | Required Surgical Edits | Existing Tests to Copy & Adapt |
|:--|:--|:--|:--|
| `src/` | `corex/packages/core/src/Database/{Contracts/*,Data/*,Enums/*,Exceptions/*,Internal/{DefaultSchemaMigrator,TaggedSchemaSets},Commands/*}.php`<br>`commerz/packages/core/src/Schema/{Contracts/SchemaOwnership,Internal/SchemaOwnershipRegistry,ConstraintNames,CommerzMigration,SchemaFingerprint}.php`<br>`chatom/packages/core/src/Schema/{Contracts/SchemaComponent,Contracts/SchemaStep,Internal/Services/SchemaCompiler}.php` | Namespace `Packman\Schema\*`; rename `CommerzMigration` -> `PackmanMigration`; keep longest-noun matching in `SchemaOwnershipRegistry`, 63-byte `ConstraintNames::shorten()`, `SchemaSet` (`Central\|Tenant\|Dedicated`, `Platform\|Module`), and timestamp-ordered `SchemaStep` compiler. | `corex/packages/core/tests/Unit/{SchemaSetBoundary,SchemaStatusContract}Test.php`<br>`corex/tests/Feature/{SchemaSets,SchemaSetsPg}Test.php`<br>`commerz/packages/core/tests/Feature/SchemaTest.php`<br>`chatom/tests/Unit/Schema/{SchemaCompiler,SchemaNames,StorageCollisionDetector}Test.php` |

---

### Package 5: `packages/panels` (`axiomasoft/packman-panels` — `Packman\Panels\`)

| Target Location in `packman/packages/panels` | Exact Source Files to Copy | Required Surgical Edits | Existing Tests to Copy & Adapt |
|:--|:--|:--|:--|
| `src/` + `database/migrations/tenant/` | `corex/packages/panels/src/{Contracts,Data,Internal,Laravel,SavedViews}/**` + `database/migrations/tenant/*`<br>`azguard/packages/core/src/{Contracts/Panels/PanelRegistry,Panels/Panel,Panels/PanelBuilder,Panels/Internal/{PanelRecipe,PanelRegistry},Catalog/Internal/PanelCatalog,Authorization/Internal/PanelAdmission}.php`<br>`proxyma/packages/core/src/Domain/Panels/{PanelSlot,PanelFormData}.php` & `Application/Panels/{PanelFormProjection,PanelSubjects}.php` | Namespace `Packman\Panels\*`; keep `ModulePanelCatalogue` / `EntityViewSchemaProvider` guarded cleanly via `interface_exists(ModuleRegistry::class) && $app->bound(ModuleRegistry::class)` so `panels` works standalone OR with `packman-modules`; merge `PanelRecipe` two-phase freeze + `PanelSlot` + `PanelFormData` (bounds clamping + `editVersion`) + `PanelAdmission`. | `corex/packages/panels/tests/**`<br>`azguard/tests/Unit/Panels/{PanelBuilder,PanelRegistry,Panel}Test.php`<br>`azguard/tests/Unit/Catalog/PanelCatalogTest.php`<br>`proxyma/packages/core/tests/Contract/PanelBindingTest.php` |

---

### Package 6: `packages/filament` (`axiomasoft/packman-filament` — `Packman\Filament\`)

| Target Location in `packman/packages/filament` | Exact Source Files to Copy | Required Surgical Edits | Existing Tests to Copy & Adapt |
|:--|:--|:--|:--|
| `src/` | `azguard/packages/filament/src/Internal/{Authorization/{AuthorizesActions,RefusesEditableColumns,RefusesSurfaceBoot,RefusesSurfaceCalls},Exports/RefusesUnverifiableDownload}.php`<br>`proxyma/packages/filament/src/BindingEditor.php`<br>`vaulter/packages/filament/src/VaulterPlugin.php` (pattern for `current()` & gated resources) | Namespace `Packman\Filament\*`; generalize `AuthorizesActions` / `RefusesSurfaceBoot` / `RefusesSurfaceCalls` to evaluate against host-bound panel gate contracts; extract generic AES-encrypted Livewire context helper in `BindingEditor`. | `proxyma/packages/filament/tests/CatalogAuthorizationTest.php`<br>`azguard/tests/Regression/DeniedHooksAcrossSurfacesTest.php` + Filament authorization tests |

---

### Package 7: `packages/testkit` (`axiomasoft/packman-testkit`, `require-dev`)

| Target Location in `packman/packages/testkit` | Exact Source Files to Copy | Required Surgical Edits | Existing Tests to Copy & Adapt |
|:--|:--|:--|:--|
| `src/` | `chatom/packages/testkit/src/{PackageTestCase,Internal/Services/TestDatabaseGuard}.php`<br>`commerz/packages/testkit/src/{Concurrency,PluginContractTests}.php`<br>`corex/packages/panels/tests/IsolatesDatabasePath.php`<br>`corex/tests/Concerns/MigratesSchemaSets.php`<br>`corex/packages/tools/tests/Support/AssertsRegisteredToolErrors.php`<br>`corex/packages/core/tests/Modules/Unit/ModulesOptionsTest.php` (seed for `OptionsConformanceTrait`) | Namespace `Packman\Testkit\*`; parameterize `TestDatabaseGuard` env var (`PACKMAN_TEST_DESTRUCTIVE_OPT_IN`); add Conformance traits (`Options`, `PluginContractTests`, `ModuleManifest`, `PanelProvider`, `ServiceProvider`). | `commerz/packages/core/tests/Feature/ConcurrencyTest.php`<br>`chatom/tests/Feature/Testkit/PackageSchemaReuseTest.php`<br>`chatom/tests/Unit/Testkit/ContractSchemaTest.php` |

---

### Package 8: `packages/devtools` (`axiomasoft/packman-devtools`, `require-dev`)

| Target Location in `packman/packages/devtools` | Exact Source Files to Copy | Required Surgical Edits | Existing Tests to Copy & Adapt |
|:--|:--|:--|:--|
| `src/Scaffold/` & `Console/Make*` & `stubs/` | `azguard/packages/core/modules/devtools/src/Internal/{Scaffold/{StubStore,Layout,Place,GeneratedFile,ProviderRegistration},Console/Make/MakeCommand}.php` | Namespace `Packman\DevTools\*`; adapt `Layout` & `StubStore` for the 4 profiles (`php-library`, `laravel-package`, `modular-platform`, `product`). | `azguard/tests/Modules/DevTools/Feature/Generators/*` (10 test files) |
| `src/Surface/`, `src/Errors/`, `src/Gates/`, `src/Rector/`, `config/` | `corex/packages/core/src/Attributes/Tooling/*` & `Errors/Tooling/*`<br>`corex/scripts/*-gate.*`, `php-api.php`, `error-registry.php`, `quality/rector/Laravel/*`, `pint.json`, `phpstan.neon`<br>`chatom/tests/Arch/Support/{OwnersCatalogue,ArchitectureGateScanner}.php` & `scripts/{generate-error-codes,generate-config-schema}.php` | Parameterize every gate to read root `packman.json` + `schemas/profiles/*.profile.json`; remove all `CoreX\*` assumptions; copy `corex/tests/fixtures/{schema-gate,extension-contract,attributes-architecture}` as gate fixtures. | `corex/packages/core/tests/Attributes/SurfaceScannerTest.php`<br>`corex/tests/Arch/*` + `corex/tests/Unit/SchemaGateTest.php`<br>`chatom/tests/Arch/*` |
| `src/Distribution/` & `src/AiSync/` | `corex/scripts/distribution/*`<br>`chatom/split.json` (pattern)<br>`azguard/compatibility.json` (pattern)<br>`corex/.codex/agents/*` & `.claude/rules/*` (fixed templates) | Fix `.codex/agents/*.toml` deserialization errors (`BundledSkillsConfig`, remove `command_requirements`); implement idempotent `ai:sync --dry-run` preserving `*-reviewer-overrides.md`. | `corex/scripts/distribution/tests/*` (Python pytest suite) + unit tests for `AiSync` & `MonorepoSplitter` |

---

## 3. Execution Waves for Stage 1 (Building `packman` Itself)

| Wave | Scope | Parallel Execution Strategy | Mandatory Exit Check |
|:--|:--|:--|:--|
| **Wave 1.1** | Root monorepo (`composer.json`, `packman.json`, `schemas/*`, `Makefile`, `pint.json`, `rector.php`, `phpstan.neon`) + **`packages/core`** (`Attributes`, `Foundation`, `Context`, `Data`, `Config`, `Errors`, `Doctor`) | 1 architect/builder + `Hydrogen` workers for `Config`, `Errors`, `Doctor` | `composer lint:check && composer analyse && composer test` on `packages/core` |
| **Wave 1.2** | Runtime Add-ons in parallel: **`packages/schema`**, **`packages/plugins`**, **`packages/modules`** (Opus 111 files + migrations + `extension.neon`), **`packages/panels`**, **`packages/filament`** | Parallel fan-out (`run_workflow` or `swarm` with `workspace: "share"` / disjoint packages) | Each package passes its copied unit/feature/PG tests; `grep -rni "corex" packages/` = `0` |
| **Wave 1.3** | Dev Tooling & AI Center: **`packages/testkit`**, **`packages/devtools`** (`bin/packman`, `StubStore`, 16 gates, Rector, `split`, `ai:sync`), **`ai/`** (skills + fixed `.toml` agents), **`fixtures/`** (`standalone-php`, `standalone-laravel`, `modular-platform`) | Parallel workers: Testkit / Gates / Scaffolder + AI Pack / Consumer Fixtures | Scaffolded test package passes all 16 gates; all 3 consumer fixtures `composer install` & pass |
| **Wave 1.4** | Full Monorepo Verification & Independent Review | Full suite (`lint:check`, `analyse`, `refactor:check`, `test`, `test:pg`, `gate`, `php-api`) + `codex -a never exec --sandbox read-only --model gpt-6-astra` | Zero blockers from `gpt-6-astra`; clean `git status` & baseline |
