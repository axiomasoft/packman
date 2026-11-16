# 03. GPT-6 Astra Architectural Review Record & Final Resolution

- **Model:** `gpt-6-astra` (`reasoning_effort = "xhigh"`, OpenAI Codex v0.169.0, `--sandbox read-only`)
- **Session ID:** `01a1e454-c26b-7e71-881f-e9d7cd076fe7`
- **Review Date:** 2026-11-16
- **Owner Final Directives (2026-11-16):**
  1. **Zero `corex` mentions & zero bridges inside `packman`:** `packman` is the universal ecosystem foundation monorepo consumed by `corex` and all other packages. Adapt Opus's `Modules` from `corex` into universal `Packman\Modules`.
  2. **Whole Unified Kernel (`packages/core` -> `axiomasoft/packman`, PSR-4 root `Packman\`):** Do not over-fragment core primitives (`Attributes`, `Foundation`, `Context`, `Data`, `Config`, `Errors`, `Doctor`) into micro-packages or separate `quality-gates` from `devtools`. Keep one cohesive kernel (`packages/core`) and focused add-ons (`modules`, `plugins`, `schema`, `panels`, `filament`, `testkit`, `devtools`).

---

## 1. Findings from `gpt-6-astra` & Exact Resolutions Applied

### Finding 1 [Blocker]: `CoreX\Modules` Had Direct Imports Into `CoreX\*` Kernel Subsystems
- **What `gpt-6-astra` Found:** `corex/packages/core/src/Modules` (111 files) directly imported `CoreX\Context\Data\TenantContext`, `CoreX\Features\Data\FeatureFlagDefinition`, `CoreX\Data\Laravel\JsonDocuments`, `CoreX\Errors\*`, `CoreX\Config\*`, `CoreX\Database\*`, `CoreX\Messaging\Events\DomainEvent`, `CoreX\Audit\*`, and `CoreX\Settings\Contracts\SettingDefaultsProvider`.
- **Final Resolution:**
  - Zero bridges and zero mentions of `corex` inside `packman`.
  - Adapt all 111 files into `packages/modules` (`axiomasoft/packman-modules`, `Packman\Modules\*`) by replacing all **29 non-`Modules` `CoreX\*` imports** with universal `Packman\*` kernel contracts (`Packman\Context\Contracts\{ContextScopeInterface,ContextResolverInterface,ActorResolverInterface}`, `Packman\Features\*`, `Packman\Settings\*`, `Packman\Events\DomainEvent`, `Packman\Data\Laravel\JsonDocuments`, `Packman\Config\*`, `Packman\Errors\*`) and `Packman\Schema\*`, while making all **13 hardcoded strings** configurable via `ModulesOptions`.
  - Opus's compilation pipeline, 8 extenders, transactional lifecycle, and 11 PHPStan rules remain 100% preserved algorithmically; `corex` cleanly consumes `axiomasoft/packman-modules`.

### Finding 2 [Major]: Separate Intra-Package Plugins from Runtime Composer-Discovered Modules
- **What `gpt-6-astra` Found:** Commerz plugins, Chatom plugins, Vaulter slot/capability modules, and AzGuard panel plugins represent lightweight compile-time intra-package extension systems (zero `mod_modules`/`mod_records` lifecycle tables).
- **Final Resolution:**
  - `packman` maintains two clean add-on packages atop `packages/core`: **`packages/plugins`** (`axiomasoft/packman-plugins`) for lightweight compile-time plugin/capability/slot graphs, and **`packages/modules`** (`axiomasoft/packman-modules`) for Composer-discovered runtime platform modules.

### Finding 3 [Major]: Prevent Dependency Cycles & Keep Pure-PHP Compatibility
- **What `gpt-6-astra` Found:** Separating `DescribesError` from `ErrorDescriptor` would cause a cycle (`contracts → errors → contracts`), and requiring `illuminate/*` on the pure contract layer would break pure-PHP packages like `tenant/packages/tenant`.
- **Final Resolution:**
  - Consolidating core primitives into **one whole kernel `packages/core` (`axiomasoft/packman`, `Packman\`)** puts `Attributes`, `Context`, `Data`, `Config`, `Errors` (both contracts and `CodedException`), and `Doctor` inside one cohesive package (zero inter-package cycles).
  - Keeping `packages/core/composer.json` free of mandatory Laravel framework dependencies (`"require": {"php": "^8.4", "psr/clock": "^1.0"}` + `"suggest": {"illuminate/support": "^13.0"}`, activating `PackmanServiceProvider` and `Laravel\*` registrars only when Laravel is booted) preserves 100% pure-PHP compatibility for `php-library` packages like `tenant/packages/tenant` and `billium/packages/contracts`.

### Finding 4 [Major]: Keep Panels Standalone & Dev Tooling in `require-dev`
- **What `gpt-6-astra` Found:** `ModulePanelCatalogue` depends on `EntityRegistry` and `ModuleRegistry`, and dev tooling (`testkit`, gates, scaffolding) must never enter production dependencies.
- **Final Resolution:**
  - `axiomasoft/packman-panels` works completely standalone (`InMemoryPanelCatalogue`, `PanelRegistry`, `PanelBuilder`, `PanelRecipe`, `PanelSlot`, `PanelFormData`, `PanelAdmission`, `SavedViews`), only activating `ModulePanelCatalogue` when `Packman\Modules\Contracts\ModuleRegistry` is installed and bound (`"suggest"`).
  - All testing harnesses live in `packages/testkit` (`axiomasoft/packman-testkit`, `require-dev`), and all 16 architecture gates + scanners + Rector rules + scaffolder + `ai:sync` + `split` live together in `packages/devtools` (`axiomasoft/packman-devtools`, `require-dev`).

### Finding 5 [Major]: Parameterize Gates & Synchronize AI Tooling via Versioned Plugin
- **What `gpt-6-astra` Found:** Legacy scripts hardcoded CoreX assumptions, `dev-doctrine.md` requires plugin-connected skills instead of raw vendoring, and `corex/.codex/agents/*.toml` failed deserialization in Codex v0.169.0.
- **Final Resolution:**
  - `packages/devtools` executes reusable gate engines driven by `schemas/packman.schema.json` + `schemas/profiles/*.profile.json`, tested with positive/negative fixtures.
  - `packman/ai/` ships as a versioned skill-plugin & agent-pack (`ai/agent-pack.json`, `ai/plugin.json`) synced idempotently via `packman ai:sync --dry-run` while preserving `*-reviewer-overrides.md` and fixing `.codex/agents/*.toml`.
