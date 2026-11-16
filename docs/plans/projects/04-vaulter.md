# Project Migration Spec 04: `vaulter`

**Repository Path:** `/home/vostrikov/projects/packages/vaulter`  
**Target Profile:** `laravel-package` (9 PHP subpackages: `core`, `drive`, `documents`, `media`, `collaboration`, `azguard`, `filament`, `webhooks`, `vaulter` + JS packages `vue`, `vue-collaboration`)  
**Zero `corex` Coupling:** Standalone document/drive platform aligned with `axiomasoft/*` naming and consuming `axiomasoft/packman` + `packman-{plugins,schema,filament,testkit,devtools}`.

---

## 1. Best Practices Extracted from `vaulter` into `packman`

1. **`Packman\Plugins` Extensions & Capabilities (from `packages/core/src/Modules/*`):**
   - `VaulterModule` / `ModuleBuilder` two-phase offer-then-seal registration (`Booting` hook seals `ModuleCatalog` before any `boot()` runs).
   - `Cardinality` (`RequiredSingle`, `OptionalSingle`, `Many`) + `SlotKind` (`Guard`, `Restriction`, `Contributor`, `Handler`, `EventSink`, `OutboxConsumer`, `OperationMiddleware`).
   - `CapabilityDefinition` + `CapabilityProvider::providesFromConfig($contract, $configKey, $default)`.
   - `SlotDefinition` + `SlotContribution` + `ExtensionRegistry` (`contributions`, `resolve`, `handlerKeys`, `handler`, `handlerClass`) — requiring explicit module dependency to contribute to a foreign slot.
2. **`Packman\Config` (`REPLACEMENT_MAPS` & Schedule Parsing):**
   - `ConfigNormalizer::REPLACEMENT_MAPS` pattern: host-defined driver/handler maps completely replace package defaults rather than recursively dot-merging.
   - `ConfigValidator` closure-free checks (`assertModel`, `assertImplements`) + `ScheduleFrequency` parsing.
3. **`Packman\Doctor` (in `axiomasoft/packman`, from `packages/core/src/Doctor/*`):**
   - Dotted-segment exact prefix filtering (`DoctorRegistry::matching(['core.schema'])` matches `core.schema.tables` without false-matching `core.schemaX`).
   - `ModuleCacheFreshnessCheck` (comparing active fingerprint against `_module_fp` cached route defaults).
4. **`Packman\Schema` (from `packages/core/src/Schema/*`):**
   - Topological `ModuleSchemas` creation/teardown + one-line migrations delegating to Schema classes (supporting instant hermetic test setup).
5. **`Packman\Filament` (from `packages/filament/src/*`):**
   - Subsystem-gated resources (`VaulterPlugin::enabledResources()`) + typed `VaulterPlugin::current(Panel $panel)`.

---

## 2. Vendor Alignment & `Spatie\LaravelPackageTools` Removal

1. **Vendor Rename (`axioma-studio/*` -> `axiomasoft/*`):**
   - Update root (`vaulter-monorepo`) and all 9 PHP subpackages (`core`, `drive`, `documents`, `media`, `collaboration`, `azguard`, `filament`, `webhooks`, `vaulter`) + `release/packages.json` from `axioma-studio/vaulter-*` to `axiomasoft/vaulter-*`.
   - Include `"replace": { "axioma-studio/vaulter-*": "self.version" }` in each subpackage `composer.json` during the transition cycle for full backwards compatibility.
2. **Remove `spatie/laravel-package-tools` from all 7 Subpackages (`core`, `drive`, `documents`, `media`, `collaboration`, `filament`, `webhooks`):**
   - Remove `"spatie/laravel-package-tools"` from each `composer.json`.
   - Convert `VaulterServiceProvider`, `VaulterDriveServiceProvider`, `VaulterDocumentsServiceProvider`, `VaulterMediaServiceProvider`, `VaulterCollaborationServiceProvider`, `VaulterFilamentServiceProvider`, and `VaulterWebhooksServiceProvider` to extend `Illuminate\Support\ServiceProvider` directly (using `Packman\Foundation\Concerns\RegistersPackageRegistrars` / thin `register()`).

---

## 3. File-by-File Migration Spec

### 3.1. Shared Primitives Replacement in `packages/core`
- **Attributes (`src/Attributes/`)**: Replace `Api.php`, `Internal.php`, `Spi.php`, `SpiKind.php` with `Packman\Attributes\*` (`axiomasoft/packman`); keep domain-specific `EventSpec.php`, `Icon.php`, `Label.php`.
- **Modules/Extensions (`src/Modules/`)**: Rebase `Cardinality`, `SlotKind`, `CapabilityDefinition`, `CapabilityProvider`, `SlotDefinition`, `SlotContribution`, `ExtensionRegistry` onto `Packman\Plugins\*`. Refactor `ModuleBuilder`, `ModuleCompiler`, `CompiledModules`, `ModuleApplier` to consume `Packman\Plugins` & `Packman\Config` while retaining Vaulter's `OffersModules` domain wiring.
- **Configuration (`src/Configuration/` & all subpackages)**:
  - **Architectural Upgrade:** Convert `VaulterConfig`, `DatabaseConfig`, `IdsConfig`, `ObservabilityConfig`, `OutboxConfig`, `RoutesConfig`, `ScheduleConfig`, `TenancyConfig`, plus `DriveConfig`, `DocumentsConfig`, `MediaConfig`, `CollaborationConfig`, `AzGuardConfig`, `FilamentConfig`, and `WebhooksConfig` from wrappers storing a live `Illuminate\Contracts\Config\Repository` into immutable `final readonly *Options` snapshots implementing `Packman\Config\Contracts\OptionsFactory` (`fromArray()`).
  - Refactor `ConfigValidator` & `ConfigNormalizer` onto `Packman\Config`.
- **Doctor (`src/Doctor/`)**: Replace `Vaulter\Contracts\Doctor\DoctorCheck` & `DoctorFinding` with `Packman\Doctor\*`; rebase `DoctorRegistry` & `ConfigValidCheck` on `Packman\Doctor\*`; update all 30+ domain checks across `core`, `drive`, `documents`, `media`, `collaboration`, `webhooks`, `azguard` to return `CheckResult` / `DoctorFinding`.
- **Schema (`src/Schema/`)**: Bridge `ModuleSchema` / `ModuleSchemas` / `KernelSchema` and subpackage schemas (`DriveSchema`, `DocumentsSchema`, `MediaSchema`, `CollaborationSchema`, `WebhooksSchema`) with `Packman\Schema\Contracts\SchemaSetProvider`.

### 3.2. Changes Across the 8 Module Manifests
- **`CoreModule`**: Switch to `Packman\Attributes\Internal` & `Packman\Doctor\Contracts\DoctorCheck`; bind `VaulterOptions`; register `DoctorCheck::class` slot (`ContributionKind::Handler`, `Cardinality::Many`).
- **`DriveModule`**: Switch to `Packman` contracts/enums; bind `DriveOptions`; wire capabilities (`DriveHandleFactoryContract`, `NodePresenter`) & 10 slots (`DriveExtension`, `PermissionDriver`, `FileHandler`, etc.) + 13 doctor checks.
- **`DocumentsModule`**: Switch to `Packman` contracts/enums; bind `DocumentsOptions`; wire `ExportFilenameResolver`, `DocumentType`, `DocumentExporter`, `CollaborationProtocolDriver`, `PendingStateDrainer`.
- **`MediaModule`**: Switch to `Packman` contracts/enums; bind `MediaOptions`; wire `ContentScanner` (`OptionalSingle`) and `StorageDriver` (`RequiredSingle`) via `providesFromConfig()`.
- **`CollaborationModule`**: Retain `Activation::OptIn` and `GuardsRemoval`; bind `CollaborationOptions`; wire `HocuspocusCollaborationDriver`, `HubDrainer`, `GenerationCloser`, `PermissionVersionSink`, `ControlConsumer`.
- **`AzGuardModule`**: Bind `AzGuardOptions`; provide `AzGuardGuard` from config; contribute `AzGuardPermissionDriver` into `Drive`'s `PermissionDriver` slot.
- **`FilamentModule` & `VaulterPlugin`**: Bind `FilamentOptions`; `VaulterPlugin` implements `Packman\Filament\Contracts\FilamentPanelPlugin` with shared security hooks from `packman-filament`.
- **`WebhooksModule`**: Retain `Activation::OptIn`; bind `WebhooksOptions`; wire `WebhookFanoutConsumer`, `WebhooksDeliveriesCheck`, invariants.

### 3.3. VCS & AI Environment Cleanup
- Delete **85 committed run-log files** from tracking (31 in `.claude/runs/*`, 54 in `.grok/runs/*`) and add `.claude/runs/` & `.grok/runs/` to `.gitignore`.
- Add root `packman.json` (`profile: "laravel-package"`), generate `resources/contracts/php-api.json`, and run `packman ai:sync`.
