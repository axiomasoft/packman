# 02. Ecosystem Rollout & Multi-Agent Alignment Plan (9 Packages)

**Target Repositories:** `tenant`, `proxyma`, `azguard`, `vaulter`, `commerz`, `chatom`, `billium`, `corex`, `sockit`  
**Execution Principle:** One dedicated fresh-context execution agent per repository, executing directly against its dedicated specification under [`docs/plans/projects/`](projects/).

---

## 1. Unified Ecosystem Patterns Enforced Across All 9 Repositories

When rollout completes, every repository in the Axioma ecosystem visually and structurally shares the exact same architecture:

1. **Unified Kernel Consumption (`axiomasoft/packman` -> `Packman\`):**
   - Every repository replaces its private copies of `#[Api]`/`#[Spi]`/`#[Internal]`, `InvalidConfiguration`/`CodedException`, `PackageRegistrar`, `JsonDocument`, `DoctorCheck`, and `*Options` validation with `axiomasoft/packman` (`packages/core`), plus focused add-ons (`packman-modules`, `packman-plugins`, `packman-schema`, `packman-panels`, `packman-filament`) and dev tooling (`packman-testkit`, `packman-devtools`).
   - `packman` itself never mentions `corex`.
2. **Uniform Composer & Taxonomy Metadata (`extra.packman`):**
   - Root monorepo `composer.json` uses `"name": "axiomasoft/<repo>-monorepo"` with empty root `autoload.psr-4`.
   - Every subpackage uses `"name": "axiomasoft/<repo>-<subpkg>"` (with `"replace"` shims where legacy names such as `axioma-studio/vaulter-*` existed) and declares `extra.packman` (`schema-version`, `package-type`, `stability`, optional `owns`).
3. **Uniform Subpackage Directory Profile:**
   - Standard layout: `Contracts/`, `Data/`, `Enums/`, `Events/`, `Exceptions/`, `Internal/`, `Laravel/`, plus thin `<Pkg>ServiceProvider` using `RegistersPackageRegistrars` (`<= 80` lines, zero I/O in `register()`).
4. **Uniform Typed Configuration (`*Options`):**
   - Zero raw `config(...)` or `env(...)` inside domain/application services.
   - Every package/module compiles `final readonly class <Name>Options::fromArray()` via `Packman\Config` (`ConfigurationValidator` / `JournalingConfigReader` + `SectionCompiler` closed-schema checks).
5. **Uniform Plugin & Module Patterns:**
   - **Standalone Domain Packages (`commerz`, `chatom`, `vaulter`, `azguard`, `proxyma`, `billium`, `sockit`):** Use `axiomasoft/packman-plugins` (`ExtensionPlugin`, `PluginManifest`, `PluginRegistrar`, `CapabilityDefinition`, `SlotDefinition`) with pure declarative registration, zero I/O, and compile-time topological sorting + freezing.
   - **Modular Platforms (`corex`):** Use `axiomasoft/packman-modules` (`ModuleDefinition`, `ModuleManifest`, 8-stage `RegistryCompiler`, `CompiledRegistry`, `DatabaseModuleLifecycle`) configured with the platform's package types (`['corex-module']`) and metadata keys (`['corex', 'module']`).
6. **Uniform Headless Panels vs Filament UI Isolation:**
   - Domain packages define headless panels/slots/projections using `axiomasoft/packman-panels` (`PanelProvider`, `PanelDescriptor`, `PanelSlot`, `PanelFormData`, `PanelBuilder`).
   - Filament/Livewire integrations live strictly in `packages/filament` (`package-type: integration`) consuming `axiomasoft/packman-filament`.
7. **Uniform Dev Tooling & AI Environment (`packman ai:sync`):**
   - Unified `packman.json`, `pint.json`, `rector.php`, `phpstan.neon`, `Makefile`, and Composer scripts (`lint:check`, `analyse`, `refactor:check`, `test`, `test:pg`, `gate`, `php-api`).
   - Idempotent AI rules/skills/agent sync via `packman ai:sync` (preserving local overrides, purging committed `.claude/runs/*`, `.grok/runs/*`, and vendored `.agents/skills/*`).

---

## 2. Execution Sequence & Per-Project Specification Map

| Phase | Repository | Profile(s) | Key Highlights of the Migration Spec | Detailed Spec |
|:--|:--|:--|:--|:--|
| **Stage 1** | **`packman`** | `modular-platform` + `laravel-package` + `php-library` | Implement all 8 `packman` packages (`core` + `modules`, `plugins`, `schema`, `panels`, `filament`, `testkit`, `devtools`), `schemas/*`, 16 gate engines + fixtures, `bin/packman`, `ai/` plugin, and 3 consumer fixtures (`standalone-php`, `standalone-laravel`, `modular-platform`). | [01-packman-monorepo-architecture-and-tree.md](01-packman-monorepo-architecture-and-tree.md) |
| **Stage 2 (Pilot)** | **`tenant`** | `php-library` (`tenant`) + `laravel-package` (`tenant-laravel`, `tenant-postgres`) | Keep `packages/tenant` 100% pure PHP (consuming `axiomasoft/packman` pure-PHP surface); replace `FrameworkConfig` with `TenantOptions`; add `TenantDoctorCheck` & `TenantPostgresSchemaSets`; purge hardcoded `corex-template-manifest/v1\0` & `corex-template-integrity:`; fix root PSR-4 autoload. | [projects/07-tenant.md](projects/07-tenant.md) |
| **Stage 2 (Pilot)** | **`proxyma`** | `laravel-package` | Map `PanelDefinition`/`PanelSlot`/`PanelFormData` & `BindingEditor` to `packman-panels` & `packman-filament`; introduce `ProxymaOptions`, `ProxymaDoctorCheck`, `ProxymaSchemaSets`; shrink 314-line `ProxymaServiceProvider`; purge 606 vendored skills. | [projects/06-proxyma.md](projects/06-proxyma.md) |
| **Stage 3** | **`azguard`** | `laravel-package` | Extract `packages/core/modules/{scopes,audit,devtools}` to top-level `packages/{scopes,audit,devtools}`; move `ConfigSections`, `StubStore`/`MakeCommand`, and Livewire hooks to `packman`; wire `PanelRegistry`/`PanelBuilder`/`Doctor`. | [projects/05-azguard.md](projects/05-azguard.md) |
| **Stage 3** | **`vaulter`** | `laravel-package` | Rename `axioma-studio/*` -> `axiomasoft/*` (with `"replace"`); replace `Vaulter\Attributes\*`, `Vaulter\Modules\*`, `DoctorRegistry`, and `VaulterConfig` with `axiomasoft/packman` & `packman-plugins` (`*Options`); remove `Spatie\LaravelPackageTools` from all 7 packages; purge 85 committed run logs. | [projects/04-vaulter.md](projects/04-vaulter.md) |
| **Stage 4** | **`commerz`** | `laravel-package` | Migrate `Plugins/*`, `CommerzConfig`, `SchemaOwnershipRegistry`, `ConstraintNames`, `CommerzMigration`, `DoctorCommand`, and `Concurrency` to `packman`; add headless `*PanelProvider`s across the 6 domain subpackages; migrate 6 arch gates to `packman-devtools`. | [projects/02-commerz.md](projects/02-commerz.md) |
| **Stage 4** | **`chatom`** | `laravel-package` | Migrate `Plugins/*`, `Configuration/*` (`JournalingConfigReader`, `SectionCompiler`, `DefinitionsCache`), `SchemaComponent`, `DoctorReportBuilder`, `TestDatabaseGuard`, and generators across all 7 subpackages to `packman`. | [projects/03-chatom.md](projects/03-chatom.md) |
| **Stage 5** | **`billium`** | `php-library` (`contracts`) + `laravel-package` (`core`) | Preserve financial admission state machine (`ModuleAdmissionGate`, `ModuleLifecycleState`) in `billium-core`; rebase `PlatformPlugin` & `LedgerPlugin` on `packman-plugins`, `BilliumOptions` on `Packman\Config`, `BilliumSchemaSetProvider` on `packman-schema`; remove `packages/skiller`. | [projects/08-billium.md](projects/08-billium.md) |
| **Stage 6** | **`corex`** | `modular-platform` | Replace `src/Modules` (111 files) and duplicated `Attributes`/`Config`/`Errors`/`Database`/`Data`/`Panels` with `axiomasoft/packman` + `packman-{modules,schema,panels,testkit,devtools}` (configured with `corex-module` / `extra.corex.module` + optional BC class aliases for SemVer); migrate all 21 subpackages; fix broken `.codex/agents/*.toml`. | [projects/01-corex.md](projects/01-corex.md) |
| **Stage 7** | **`sockit`** | `laravel-package` | Fresh scaffold via `bin/packman new:monorepo sockit` strictly per `audits/sockit-reports-restored/*` & `plans/2026.10.17-№1-SOCKIT-FOUNDATION` (honoring M6/D14 tombstone: zero `comments` module), wiring `ReactionsPlugin`, `PostsPlugin`, `GroupsPlugin`, `SockitPanelProvider`, `SockitOptions`, and `SchemaSets`. | [projects/09-sockit.md](projects/09-sockit.md) |

---

## 3. Mandatory Verification Gate Per Repository (Zero Exceptions)

A repository agent cannot mark its migration complete until ALL of the following are verified with recorded evidence:

1. `composer install` succeeds cleanly in an isolated consumer fixture matching the package's profile.
2. `composer lint:check` (Pint), `composer analyse` (PHPStan max), and `composer refactor:check` (Rector dry-run) exit `0`.
3. `composer gate` (`packman gate` — all 16 architecture/ownership/globals/layout/provider-size gates) and `composer php-api` pass with zero unexpected breaking BC diffs.
4. `composer test` (Hermetic SQLite) AND `composer test:pg` (Isolated PostgreSQL `_test` DB) both pass 100%.
5. `grep -rn -i "corex" packages/` inside `packman` itself returns **0 matches**.
6. `packman ai:sync --dry-run` shows zero drift; zero committed run logs or raw vendored skills; `.codex/agents/*.toml` passes deserialization cleanly.
