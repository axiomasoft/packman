# 00. Ecosystem Audit & "Golden Fund" Synthesis

**Audited Repositories:** `corex`, `chatom`, `vaulter`, `azguard`, `proxyma`, `commerz`, `tenant`, `billium`, `sockit`  
**Audit Method:** Direct code & AST inspection across all 9 repositories by 7 dedicated fresh-context repository auditors.  
**Core Architectural Mandate:** `packman` uses a single whole kernel (`packages/core` -> `axiomasoft/packman`, root namespace `Packman\`) paired with clean add-on packages (`modules`, `plugins`, `schema`, `panels`, `filament`, `testkit`, `devtools`) and contains **zero mentions of `corex`**. All 9 repositories (including `corex`) become downstream consumers of `packman`.

---

## 1. Comparative Landscape: How the 9 Repositories Currently Work

Every repository solved the same foundational problems independently, polishing different aspects through multiple iterations:

### 1.1. Module & Plugin Systems Across the Ecosystem

| Repository | Mechanism & Location | Best Innovation ("Golden Fund") | Limitation / Divergence Today |
|:--|:--|:--|:--|
| **`corex`** | `packages/core/src/Modules/*` (111 files by Opus) | Composer `installed.json` discovery + test fallback; deterministic 8-stage `RegistryCompiler` (`Entity → EntityField → Permissions → Api → Filament → Menu → Routes → Workflow`); SHA-fingerprinted cached PHP registry (`0` FS scans / `0` SQL on warm requests); transactional DB lifecycle (`mod_modules`, `mod_records` claims, `mod_lifecycle_log`); 11 AST PHPStan rules. | Hardcoded `CoreX\*` imports (29 symbols) and hardcoded strings (`corex-module`, `extra.corex.module`, `corex:modules:*`, `bootstrap/cache/corex_modules.php`, `corexModules.*`). |
| **`commerz`** | `packages/core/src/Plugins/*` + `PluginGraph.php` | Pure compile-time `CommerzPlugin` (`new $class()`, zero I/O); `PluginManifest` (`vendor.name` + strict SemVer + `requires`/`optional`); Kahn's topological sort with deterministic tie-breaking; transitive ancestor ownership enforcement for `ContributionKind`; immutable `FrozenContributions` (Octane-safe). | Only handles compile-time plugin graph; duplicated attributes (`Commerz\Attributes\*`) and error classes (`InvalidConfiguration`). |
| **`chatom`** | `packages/core/src/Plugins/*` + `PollPlugin`, `WebhooksPlugin`, etc. | Capability-based decoupling (`PluginManifest` never names other plugins directly; requests capabilities/extension points); 9 partitioned sub-compilers (`Identity`, `ConfigSchema`, `OperationsStages`, `Channels`, `CapabilitiesDrivers`, `Http`, `Diagnostics`, `Delivery`, `Content`); all-at-once `ContributionChecks` accumulation; database engine compatibility checks (`assertDatabases`). | Duplicated plugin engine alongside `commerz` and `vaulter`; own `Chatom\Attributes\*`. |
| **`vaulter`** | `packages/core/src/Modules/*` | `ModuleBuilder` with explicit `Cardinality` (`RequiredSingle`, `OptionalSingle`, `Many`) and typed `SlotKind` semantics (`Guard`, `Restriction`, `Contributor`, `Handler`, `EventSink`, `OutboxConsumer`, `OperationMiddleware`); `CapabilityProvider::providesFromConfig()`; `ExtensionRegistry::handler($slot, $key)` and `handlerClass()`. | Named `VaulterModule` even though it is an in-memory intra-package extension/slot system; uses `Vaulter\Attributes\*`. |
| **`azguard`** | `packages/core/src/Contracts/Plugins/Plugin.php`, `ScopesPlugin`, `AuditPlugin` | Per-panel scoped `Plugin` (`register(PanelBuilder $panel, PluginContext $context)`); fluent static constructors (`ScopesPlugin::inherit(...)`, `AuditPlugin::make(90)`); typed options injection (`$panel->options(new AuditOptions(...))`). | Plugins live inside `packages/core/modules/{scopes,audit,devtools}` with eager providers in `packages/core/composer.json`. |
| **`proxyma`** | `packages/core/src/Application/Extensions/*` (`DefinitionRegistry`) | `DefinitionRegistry::freeze()` with DFS cycle detection, `AdmissionLockTier` dependency ordering, `approved_providers` allowlisting, deterministic SHA-256 fingerprint, and `[tier, priority, id]` runtime selection. | Domain-specific to `ExtensionKind` (route filters, budget scopes, admission guards). |
| **`billium`** | `packages/core/src/*/Modules/*` (`ModuleCatalog`, `ModuleLifecycle`, `ModuleAdmissionGate`) | **Financial invariant state machine**: 4 states (`Accepting`, `Draining`, `RecoveryOnly`, `Retired`) gating 8 `ModuleOperationClass`es inside pessimistic-locked root SQL transactions (`ModuleAdmissionGate`, `ModuleTransitionPlanner`, `ModuleTransitionFacts`). | Bundles generic manifest/graph traversal (`Semver.php`, `Shape.php`, `ModuleCatalog` cycle check) with billing invariants. |

**Synthesis Decision in `packman`:**
`packman` cleanly separates the two legitimate extension models into dedicated add-on packages atop `packages/core` (`axiomasoft/packman`):
1. **`axiomasoft/packman-plugins` (`Packman\Plugins\*` — Strict Code Extensions, Zero DB Required):** The universal intra-package extension, plugin, capability, and slot engine combining `commerz` (`PluginManifest`, Kahn's topological sort + ancestor ownership), `chatom` (disabled list, all-at-once `ContributionChecks`, database matrix check), `vaulter` (`Cardinality`, `SlotKind`, `CapabilityProvider::providesFromConfig()`, `ExtensionRegistry`), and `azguard` (scoped `PluginContext`). Plugins here are pure code extensions activated via configuration/code with **no database tables or records required**; hosts that persist plugin activation in DB can optionally bind `PluginActivationGate`.
2. **`axiomasoft/packman-modules` (`Packman\Modules\*` — Bitrix/WordPress-Style Stateful Platform Modules & Plugins):** The Composer-discovered, deploy-time compiled, tenant-lifecycle-aware modular platform engine adapted directly from `corex/packages/core/src/Modules` (all 111 Opus files), with all 29 external imports replaced by `Packman\*` kernel contracts/DTOs (`axiomasoft/packman`) and `Packman\Schema\*` (`axiomasoft/packman-schema`), and all 13 hardcoded strings configurable via `ModulesOptions` (discovering both module and plugin package types `['packman-module', 'packman-plugin', 'module', 'plugin']` with DB state in `mod_modules`, `mod_records`, `mod_lifecycle_log`).

---

### 1.2. Panels & UI Bridges Across the Ecosystem

| Repository | Implementation | Best Innovation ("Golden Fund") |
|:--|:--|:--|
| **`corex`** | `packages/panels/src/*` (34 files) | UI-agnostic `PanelDescriptor`, `PanelProvider`, `PanelAccess`, `PanelContext`, `EntityViewSchemaProvider`, and the complete persistence-ready `SavedViews` subsystem (`SavedViewRepository`, `ViewSchemaProvider`, `ViewAccessPolicy`, `SqlSavedViewRepository`). |
| **`azguard`** | `packages/core/src/Panels/*` & `packages/filament/*` | Two-phase `PanelRegistry` (`register`/`replace`/`configure` -> `freeze()`), append-only `PanelRecipe` with origin precedence (`provider` < `plugin` < `configureAll` < `configure($id)`), hash-indexed `PanelCatalog`, graceful `PanelAdmission` (scoped navigation discovery), and **Livewire security hardening** (`RefusesSurfaceBoot`, `RefusesSurfaceCalls`, `RefusesEditableColumns`, write-PDO `AuthorizesActions`, `RefusesUnverifiableDownload`). |
| **`proxyma`** | `packages/core/src/Domain/{Policy/PanelDefinition,Panels/*}` & `packages/filament/src/BindingEditor.php` | `PanelSlot`, `PanelFormData` (field-level inheritance, `clamped`, `minimum`, `maximum`, `clamped_by`, `editable`, `reason`, `editVersion` optimistic hash), `PanelSubjects` (anti-spoofing ancestor re-resolution), and `BindingEditor` (`Crypt::encryptString` stateless tamper-proof Livewire form context). |
| **`vaulter`** | `packages/filament/*` | Subsystem-gated Filament resources (`VaulterPlugin::enabledResources()` checks both Filament toggle and core feature flags) + typed `VaulterPlugin::current($panel)`. |
| **`sockit`** (spec) | `knowledge/panel-design.json` | Multi-panel isolation boundary (`PanelId` qualifies every storage connection, prefix, model map, outbox row, and cache key). |

---

### 1.3. Configuration Objects Across the Ecosystem (Merged into `packages/core` `Packman\Config\*`)

| Repository | Implementation | Best Innovation ("Golden Fund") |
|:--|:--|:--|
| **`chatom`** | `packages/core/src/Configuration/*` | `ConfigSection` + `JournalingConfigReader` (single-pass fault tolerance collecting all errors under dotted paths + automatic secret redaction + `unsafeBool` production guard) + `SectionCompiler` (closed-schema unknown-key rejection with **Levenshtein "did you mean?" suggestions**) + `DefinitionsCache` (SHA-256 fingerprint + round-trip codec verification + atomic file write + `opcache_invalidate`). |
| **`commerz`** | `packages/core/src/Configuration/CommerzConfig.php` | Ultra-clean fail-closed `final readonly *Options::fromArray($config, $path)` with zero silent defaults and explicit dotted error paths (`commerz-pricing.tax.calculator`). |
| **`corex`** | `packages/core/src/Config/*` | `ConfigurationValidator` (`add($path, $message)` + `failIfAny()`), `SecretRef`, `RuntimeConfigGate`, `ConfigReportSection`, `ValidateConfigCommand`. |
| **`azguard`** | `packages/core/src/Configuration/ConfigSections.php` | Modular config root ownership (`ConfigSections::own($root, $validate)`) sealing on first read and rejecting unknown root keys. |
| **`vaulter`** | `packages/core/src/Configuration/*` | `ConfigNormalizer::REPLACEMENT_MAPS` (host-defined strategy maps replace package defaults instead of dot-merging) + closure-free validation (config-cache safe) + schedule frequency parsing. |

---

### 1.4. Database Schemas, Diagnostics, Testkit & DevTools

| Domain | Target Package in `packman` | Source Repositories & Best Practices Selected |
|:--|:--|:--|
| **Errors, Attributes, Foundation, Context, Data, Config, Doctor** | **`packages/core`** (`axiomasoft/packman`, `Packman\`) | Unified whole kernel: `Attributes`, `Foundation` (`PackageRegistrar`, `ApplicationLocator`), `Context` (`ContextScopeInterface`, `ContextResolverInterface`, `ActorResolverInterface`), `Data` (`JsonDocument`/`JsonDocuments`), `Config` (`*Options`, `JournalingConfigReader`, `SectionCompiler`, `ConfigSections`, `DefinitionsCache`), `Errors` (`DescribesError`, `ErrorDescriptor`, `CodedException`, `CompiledProblemRegistry`), `Doctor` (`DoctorRegistry`, `HealthCheck`, `DoctorFinding` + cascade skip + dotted prefix filter). |
| **Schema** | **`packages/schema`** (`axiomasoft/packman-schema`) | `corex` `SchemaSet`/`SchemaMigrator` + `commerz` `SchemaOwnershipRegistry` (longest-noun match), `ConstraintNames::shorten()` (63-byte PG limit), `PackmanMigration`, `SchemaFingerprint` + `chatom` `SchemaComponent`/`SchemaStep` + `vaulter` topological `ModuleSchemas`. |
| **Testkit** | **`packages/testkit`** (`axiomasoft/packman-testkit`, `require-dev`) | `chatom` `PackageTestCase` (`migrateOncePerProcess`), `TestDatabaseGuard` + `commerz` `Concurrency::run()` (`pcntl_fork` + barrier files), `PluginContractTests` + `corex` `IsolatesDatabasePath`, `MigratesSchemaSets`, `ArrayEntityRegistry`, `ExtensionContract`. |
| **DevTools & Quality Gates** | **`packages/devtools`** (`axiomasoft/packman-devtools`, `require-dev`) | `azguard` `StubStore` (strict placeholder checks + Pint-sorted imports), `Place`/`Layout`, AST `ProviderRegistration`, 4-phase transactional `MakeCommand` + `corex` 16 static/architecture/API/schema/ownership gates + `SurfaceScanner` + `ErrorSourceScanner` + custom Laravel Rector rules + `chatom` `OwnersCatalogue`, `split.json`, generators + `azguard` `compatibility.json`. |

---

## 2. Complete Divergences & Technical Debt Found Across the 9 Repositories

1. **Vendor & Root Naming Fragmentation:**
   - `vaulter` uses `axioma-studio/vaulter-*` across all 11 subpackages while the other 8 repositories use `axiomasoft/*`.
   - `corex/packages/access-azguard/composer.json:9` declares `"axioma-studio/azguard-core": "^0.3"`, while `azguard/packages/core/composer.json` is `"axiomasoft/azguard"`.
   - Root `composer.json` names vary across `corex/monorepo`, `axiomasoft/*-monorepo`, `axioma-studio/vaulter-monorepo`, and `axiomasoft/*-workspace`.
2. **Duplicated Kernel Boilerplate:**
   - Identical `Attributes/{Api,Spi,SpiKind,Internal}` classes exist independently in `corex`, `vaulter`, `azguard`, `commerz`, `chatom`, `tenant`, `billium`.
   - Separate configuration validators, exception base classes, doctor frameworks, and API manifest scanners exist in almost every repository.
3. **Hardcoded Branding in Infrastructure Code:**
   - `tenant/packages/tenant-postgres/src/Internal/Database/TemplateManifest.php:63` hardcodes `"corex-template-manifest/v1\0"`.
   - `tenant/packages/tenant-postgres/src/Internal/Database/TemplateIntegrityLock.php:16` hardcodes `'corex-template-integrity:'`.
   - `tenant/packages/tenant-laravel/src/Internal/FrameworkConfig.php:10` references `CoreX` in its docblock.
   - `corex/packages/core/src/Modules/*` hardcodes `corex-module`, `extra.corex.module`, `corex:modules:*`, `corex.modules`, `bootstrap/cache/corex_modules.php`, and `corexModules.*`.
4. **Heavy/Inconsistent Framework Coupling:**
   - `proxyma/packages/core/src/Laravel/ProxymaServiceProvider.php` is 314 lines long with 30+ raw `$app['config']->get(...)` lookups.
   - `vaulter` pulls `spatie/laravel-package-tools` into 7 subpackages merely for `$package->name(...)`, and `VaulterConfig` retains a live `Illuminate\Contracts\Config\Repository` instance instead of compiling immutable `*Options`.
   - `tenant/composer.json` leaves PSR-4 autoloading (`"Tenant\\": "packages/tenant/src/"`) in the root monorepo manifest, and lacks `pint.json`, `rector.php`, and `.claude`/`.agents` tooling.
5. **Subpackage Organization Anomalies:**
   - `azguard` nests 3 standalone subsystems (`scopes`, `audit`, `devtools`) inside `packages/core/modules/*` with eager providers in `packages/core/composer.json`, even though `packages/core/src` has zero imports of `Scopes\*` or `Audit\*`.
   - `billium` packs 3 separate PSR-4 namespaces (`Billium\Core\`, `Billium\Platform\`, `Billium\Ledger\`) inside `packages/core` and leaves a `packages/skiller/bin/skiller -> /home/vostrikov/.local/bin/skiller` symlink inside `packages/`.
   - `sockit` has no PHP code or `composer.json` yet, only `audits/` and `plans/` (including tombstone `M6/D14` removing comments from SocKit).
6. **AI Tooling & VCS Hygiene Drift:**
   - `vaulter` has 85 committed run-log files (`.claude/runs/`: 31, `.grok/runs/`: 54; 93 total in `.claude` + `.grok`).
   - `proxyma` vendors 606 files under `.agents/skills/` (contradicting plugin-based skill connection).
   - `billium` has 336 AI/orchestration files including `.swissknifeman/orch-artifacts/*`.
   - `corex/.codex/agents/{corex-spec-writer,corex-test-writer,corex-test-verifier,corex-api-surface}.toml` fail deserialization in Codex v0.169.0 (`skills = [...]` expects `BundledSkillsConfig` struct; `command_requirements` is an unknown field).
