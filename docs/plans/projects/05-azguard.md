# Project Migration Spec 05: `azguard`

**Repository Path:** `/home/vostrikov/projects/packages/azguard`  
**Target Profile:** `laravel-package`  
**Subpackage Restructuring:** Extract `packages/core/modules/{scopes,audit,devtools}` into top-level `packages/{scopes,audit,devtools}` alongside `packages/core` and `packages/filament`.  
**Zero `corex` Coupling:** Standalone authorization engine consuming `axiomasoft/packman` + `packman-{plugins,panels,filament,testkit,devtools}`.

---

## 1. Best Practices Extracted from `azguard` into `packman`

1. **`Packman\Panels` (from `packages/core/src/Panels/*`, `PanelRegistry`, `PanelCatalog`, `PanelAdmission`):**
   - Two-phase `PanelRegistry` (`register`/`replace`/`configure`/`configureAll` during boot -> atomic immutable `freeze()` at `$app->booted(...)`; pre-freeze reads throw `DefinitionException`, post-freeze writes throw `RegistryFrozenException`).
   - Append-only **`PanelRecipe`** timeline preserving strict origin precedence (`provider` < `plugin` < `configureAll` < `configure($id)`), enforcing that plugins cannot call `withoutPlugins()` to detach sibling plugins.
   - `Panel` (`final readonly`) with extensible typed options accessor: `panel->options(class-string<T>): ?T`.
   - `PanelCatalog` partitioning static vs dynamic sources with O(1) hash maps and collision detection (`DuplicatePermissionException`, `DuplicateRoleException`).
   - `PanelAdmission`: role + entry permission + tenant policy + **graceful scoped navigation** (admits subject to global panel shell if subject holds an active role in any eligible child scope).
2. **`Packman\Plugins` & `Packman\Config` (from `Plugin`, `PluginContext`, `ScopesPlugin`, `AuditPlugin`, `ConfigSections`):**
   - Pure declarative `Plugin` (`id()`, `register($builder, PluginContext)`) receiving scoped `PluginContext(panelId, pluginId, buildId)` with zero I/O or global listeners.
   - `ConfigSections::own(string $root, Closure $validate)` freezing on first read and rejecting unknown root keys (`assertKnownKeys`).
3. **`Packman\DevTools` (from `packages/core/modules/devtools/src/*`):**
   - `StubStore`: fails loudly on unresolved `{{ var }}` placeholders; sorts `use` imports identically to Laravel Pint (`str_replace('\\', ' ', ...)`).
   - `Place` & `Layout`: paired filesystem path + PHP namespace value objects.
   - `ProviderRegistration`: PHP tokenizer (`token_get_all`) AST-safe array insertion preserving formatting & comments.
   - 4-phase transactional `MakeCommand`: Plan (`GeneratedFile[]`) -> Preflight Collision Check (zero partial writes) -> Atomic Write -> Post-Write Hook.
4. **`Packman\Filament` (from `packages/filament/src/*`):**
   - `RefusesSurfaceBoot`: `Livewire\ComponentHook` registered before Livewire's own lifecycle hooks (`mount`/`hydrate` refuse 403 before component `boot()`).
   - `RefusesSurfaceCalls` & `RefusesEditableColumns`: intercepts `call` events (blocking unauthorized inline table edits via `ImplicitlyBoundMethod`).
   - `AuthorizesActions`: configures actions + listens to `ActionCalling`; verifies bulk selection IDs against **write PDO** (`getSelectedTableRecordsQuery()->useWritePdo()`) preventing client-side ID tampering.
   - `RefusesUnverifiableDownload`: replaces `DownloadExport` controller binding + registers `Livewire::addPersistentMiddleware` for panel entry guards + `Gate::before` refusal on unowned Filament abilities.

---

## 2. Subpackage Extraction Decision: `packages/core/modules/*` -> `packages/*`

Inspecting `packages/core/src` proved **zero imports** of `AzGuard\Scopes\*` or `AzGuard\Audit\*` inside `core`, while `DevToolsServiceProvider` and `AuditServiceProvider` currently boot eagerly on every request via `packages/core/composer.json`:

| Current Location | Target Subpackage | Target Profile & Type | Justification |
|:--|:--|:--|:--|
| `packages/core/modules/scopes` | `packages/scopes` (`axiomasoft/azguard-scopes`) | `laravel-package` (`capability`) | 21-file optional scope inheritance & query filter plugin; zero coupling in core. |
| `packages/core/modules/audit` | `packages/audit` (`axiomasoft/azguard-audit`) | `laravel-package` (`capability`) | Independent migration, pruner, CLI commands & `JournalParticipant`; stops eager boot when audit unused. |
| `packages/core/modules/devtools` | `packages/devtools` (`axiomasoft/azguard-devtools`) | `laravel-package` (`tooling`, **`require-dev` only**) | Depends on `packman-devtools`; prevents pulling dev scaffolding into production `require`. |

---

## 3. Exact File-by-File Migration Spec for `azguard`

| Current `azguard` File / Class | Action | Target `packman` / New Location |
|:--|:--|:--|
| `packages/core/src/Attributes/{Api,Spi,SpiKind}.php` | **Replace / Alias** | `Packman\Attributes\{Api,Spi,SpiKind}` (`axiomasoft/packman`; retain HTTP `CheckPermission` / `SkipPermissionCheck` in `AzGuard\Attributes`) |
| `packages/core/src/Configuration/ConfigSections.php` | **Replace** | Delete local copy; consume `Packman\Config\Internal\ConfigSections` |
| `packages/core/src/Configuration/Internal/AzGuardConfig.php` | **Wire** | Validate known sections & keys using `Packman\Config\Laravel\ConfigurationValidator` & `ConfigSections` |
| `packages/core/src/Contracts/Diagnostics/DoctorCheck.php` | **Replace** | Implement `Packman\Doctor\Contracts\DoctorCheck` |
| `packages/core/src/Diagnostics/{DoctorFinding,Severity,DoctorContext,Doctor}.php` | **Replace / Wire** | Map findings to `Packman\Doctor\*` and register 20+ checks into `Packman\Doctor\Internal\DoctorRegistry` |
| `packages/core/src/Contracts/Panels/PanelRegistry.php`, `src/Panels/{Panel,PanelBuilder}.php` | **Wire** | Extend/align with `Packman\Panels\{Contracts\PanelRegistry,Builder\Panel,Builder\PanelBuilder}` |
| `packages/core/src/Contracts/Plugins/Plugin.php` | **Wire** | Align with `Packman\Plugins\Contracts\ExtensionPlugin` / `Packman\Panels` |
| `packages/core/modules/scopes/src/{ScopesPlugin,Internal/ScopesOptions}.php` | **Move & Wire** | Move to `packages/scopes`; `ScopesOptions` implements `Packman\Config\Contracts\OptionsFactory` |
| `packages/core/modules/audit/src/{AuditPlugin,AuditOptions}.php` | **Move & Wire** | Move to `packages/audit`; `AuditOptions` implements `Packman\Config\Contracts\OptionsFactory` |
| `packages/core/modules/devtools/src/Internal/Scaffold/{StubStore,Layout,Place,GeneratedFile,ProviderRegistration}.php` & `MakeCommand.php` | **Move & Replace** | Move to `packages/devtools` (`require-dev`); delegate scaffold engine & base `MakeCommand` to `Packman\DevTools\*` |
| `packages/filament/src/Internal/Authorization/{AuthorizesActions,RefusesSurfaceBoot,RefusesSurfaceCalls,RefusesEditableColumns}.php` | **Replace / Consume** | Extracted into `axiomasoft/packman-filament`; `azguard-filament` consumes shared hooks and supplies AzGuard gate policy |
| `packages/filament/src/AzGuardFilamentServiceProvider.php` | **Wire** | Register `Packman\Filament` Livewire/export security hooks + AzGuard authorization bindings |
| `packages/core/composer.json` | **Update** | Remove `AuditServiceProvider` & `DevToolsServiceProvider` from `extra.laravel.providers`; require `axiomasoft/packman`, `packman-panels`, `packman-plugins` |
| Root `packman.json`, `composer.json` | **Update / Add** | Declare 5 packages (`core`, `scopes`, `audit`, `filament`, `devtools`), adopt `packman-devtools` + `packman-testkit`, refresh `compatibility.json` + `php-api.json`, run `packman ai:sync` |
