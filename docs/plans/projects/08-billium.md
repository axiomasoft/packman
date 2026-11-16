# Project Migration Spec 08: `billium`

**Repository Path:** `/home/vostrikov/projects/packages/billium`  
**Target Profiles:**
- `packages/contracts` (`axiomasoft/billium-contracts`): **`php-library`** (pure PHP + `ext-mbstring` + `brick/math` + `brick/money`)
- `packages/core` (`axiomasoft/billium-core`): **`laravel-package`**
- `packages/contracts-dart`: Cross-language Dart client SDK (preserved + tested via `conformance/`)  
**Zero `corex` Coupling:** Preserves financial double-entry invariants and transactional module gating atop `axiomasoft/packman` + `packman-{plugins,schema,testkit,devtools}`.

---

## 1. Crucial Architectural Distinction: Billium's `Modules` vs `Packman\Modules`

Deep audit of `billium/packages/core/src/*/Modules/*` (`ModuleRegistry`, `ModuleCatalog`, `ModuleLifecycle`, `ModuleAdmissionGate`, `ModuleTransitionPlanner`, `ModuleTransitionEvidence`) revealed:
- Billium's module state machine (`Accepting <-> Draining -> RecoveryOnly -> Retired`) is **not** a generic Composer package loader — it is a **deterministic financial transaction gate** read inside pessimistic-locked root SQL transactions (`billium_module_state`) that blocks unsafe money operations (`NewIntent`, `OriginalOwnerRefund`, etc. across 8 `ModuleOperationClass`es) and blocks retirement if `feedGaps > 0` or `unexportedFacts > 0`.
- **Correct Boundary Split:**
  - Generic graph validation, cycle detection, plugin capability/slot declarations, config options validation, and database schema ownership move to **`axiomasoft/packman`**, **`packman-plugins`**, and **`packman-schema`**.
  - Financial domain invariants (`ModuleAdmissionGate`, `ModuleStateReader`, `ModuleTransitionEvidence`, `ModuleTransitionPlanner`, `ModuleLifecycleState`, `ModuleOperationClass`, `TransitionBlockerCode`, `ModuleLifecycle`) **remain intact** inside `billium-core`!

---

## 2. Exact File-by-File Migration Spec for `billium`

### 2.1. `billium/packages/core/src/*/Modules/*` Refactoring Matrix
| Current `billium` File | Action | Target `packman` / New Responsibility |
|:--|:--|:--|
| `Contracts/Modules/ModuleAdmissionGate.php`, `ModuleStateReader.php`, `ModuleTransitionEvidence.php`, `ModuleTransitionPlanner.php` | **Retain (Domain Core)** | Decorate with `Packman\Attributes\{Api,Spi}` |
| `Contracts/Modules/ModuleRegistry.php` | **Refactor** | Keep domain compatibility check; delegate graph/cycle checks to `Packman\Plugins` (`PluginInspector`, `PluginGraphCompiler`) |
| `Data/Modules/{ModuleState,ModuleTransitionFacts,ModuleTransitionPreview}.php`, `Enums/Modules/*`, `Internal/Modules/ModuleLifecycle.php` | **Retain (Domain Core)** | High-integrity financial state machine |
| `Data/Modules/ModuleManifest.php`, `ModuleDescriptor.php` | **Refactor** | Base plugin identity/deps moved to `Packman\Plugins\Data\PluginManifest`; table ownership moved to `SchemaSet`; financial policies (`DisablePolicy`, `RetirementPolicy`) kept in Billium descriptor |
| `Data/Modules/ModuleDependency.php` | **Replace** | Use `Packman\Plugins\Data\PluginManifest` (`requires`, `optional`) |
| `Data/Modules/MigrationIdentity.php` | **Replace** | Use `Packman\Schema\Data\SchemaSet` & migration files |
| `Internal/Modules/Semver.php` | **Replace** | Use `Packman\Plugins` SemVer helper |
| `Internal/Modules/Shape.php` | **Replace** | Use `Packman\Config\Laravel\ConfigurationValidator` / `JournalingConfigReader` |
| `Internal/Modules/ModuleCatalog.php` | **Refactor** | Delegate topological boot-order sort & cycle detection to `Packman\Plugins\Internal\PluginGraphCompiler` and table ownership uniqueness to `Packman\Schema` |

### 2.2. Structuring `modules/platform` and `modules/ledger` as `Packman\Plugins`
- **`Billium\Platform\PlatformPlugin`** (`packages/core/modules/platform/src/PlatformPlugin.php`):
  - Implements `Packman\Plugins\Contracts\ExtensionPlugin`.
  - `manifest()`: ID `'axiomasoft.billium.platform'`, version `'1.0.0'`.
  - Declares capabilities (`billium.idempotency`, `billium.outbox`, `billium.sessions`, `billium.feed-coordinator`) and host/optional slots (`EventPublisher`, `WriterAdmission`).
- **`Billium\Ledger\LedgerPlugin`** (`packages/core/modules/ledger/src/LedgerPlugin.php`):
  - Implements `Packman\Plugins\Contracts\ExtensionPlugin`.
  - `manifest()`: ID `'axiomasoft.billium.ledger'`, requires `'axiomasoft.billium.platform'`.
  - Declares capabilities (`billium.ledger`, `billium.invoicing`, `billium.allocations`), fulfills `PaymentObservationSink` on `platform`.

### 2.3. `BilliumOptions`, `SchemaSets` & `DoctorCheck`
1. **Create `packages/core/src/Configuration/BilliumOptions.php`**:
   - Immutable `final readonly` options (`connection`, `installationRef`, `RunnerOptions`, `FinancialSessionOptions`, `IdempotencyOptions`, `OutboxOptions`, `FeedOptions`, `InboxOptions`, `LedgerOptions`, `ModuleCompositionOptions`) implementing `Packman\Config\Contracts\OptionsFactory::fromArray()`.
   - Eliminates raw `config('billium.*')` access inside `BilliumCoreServiceProvider` and domain services.
2. **Create `packages/core/src/Database/BilliumSchemaSetProvider.php`**:
   - Implements `Packman\Schema\Contracts\SchemaSetProvider` registering:
     - `SchemaSet('billium-platform', SchemaScope::Central, SchemaLifecycle::Platform, path: '.../database/migrations/platform')` (20 owned `billium_*` tables)
     - `SchemaSet('billium-ledger', SchemaScope::Central, SchemaLifecycle::Module, path: '.../database/migrations/ledger', requires: ['billium-platform'])` (14 owned `billium_ledger_*` tables)
   - Replaces raw `$this->loadMigrationsFrom(...)` in `BilliumCoreServiceProvider::boot()`.
3. **Create `packages/core/src/Diagnostics/BilliumCompatibilityDoctorCheck.php`**:
   - Implements `Packman\Doctor\Contracts\DoctorCheck` bridging `ModuleRegistry::check()` into `Packman\Doctor`.
4. **Attributes (`packages/contracts/src/Attributes/*`)**:
   - Require `axiomasoft/packman` (pure-PHP surface) in `packages/contracts/composer.json` (zero Laravel dependency preserved); replace/alias `Billium\Contracts\Attributes\{Api,Spi,SpiKind}`.

### 2.4. Monorepo Hygiene & Subpackage Alignment
- Rename root `"axiomasoft/billium-workspace"` -> `"axiomasoft/billium-monorepo"`.
- Remove `packages/skiller` directory and its symlink `packages/skiller/bin/skiller -> /home/vostrikov/.local/bin/skiller` from `packages/`.
- Add `.swissknifeman/orch-artifacts/` to `.gitignore` and untrack existing orchestration artifact logs.
- Declare `packages/contracts` (`php-library`) and `packages/core` (`laravel-package`, explicitly profiling `Billium\Core\`, `Billium\Platform\`, `Billium\Ledger\`) in root `packman.json`; generate `php-api.json`; run `packman ai:sync`.
