# Project Migration Spec 06: `proxyma`

**Repository Path:** `/home/vostrikov/projects/packages/proxyma`  
**Target Profile:** `laravel-package` (2 subpackages: `core`, `filament`)  
**Zero Host-Product Coupling:** Pure standalone outbound transport & policy panel engine consuming `axiomasoft/packman` + `packman-{plugins,panels,schema,filament,testkit,devtools}`.

---

## 1. Best Practices Extracted from `proxyma` into `packman`

1. **`Packman\Panels` (from `packages/core/src/Domain/{Policy/PanelDefinition,Panels/*}` & `Application/Panels/*`):**
   - `PanelSlot` (`id` matching `/^[a-z0-9][a-z0-9_.:-]{0,127}$/D`, settings list, defaults, inheritance flag).
   - `PanelFormData`: headless form projection DTO carrying `panelKey`, `slotKey`, `binding`, field map with **`value`, `inherited`, `source`, `revision`, `clamped`, `minimum`, `maximum`, `clamped_by`, `clamped_revision`, `editable`, `reason`**, `blockedReason`, and `editVersion` optimistic concurrency hash.
   - `PanelSubjects`: hierarchical subject resolution (`SubjectChain`) with **anti-spoofing ancestor re-resolution** via `SubjectResolver`.
   - `PanelFormProjection`: authorization + binding + effective policy clamping + concurrency drift guard (`BindingEditVersion`).
2. **`Packman\Plugins` (from `packages/core/src/Application/Extensions/DefinitionRegistry.php`):**
   - `DefinitionRegistry::freeze()`: deterministic SHA-256 fingerprint over sorted definitions, DFS cycle detection, lock-tier dependency ordering, and `approved_providers` allowlisting.
3. **`Packman\Filament` (from `packages/filament/src/BindingEditor.php`):**
   - **Stateless Encrypted Livewire Context:** `Crypt::encryptString(json_encode([...]))` wrapping `tenant`, `type`, `id`, `target`, `panel`, `slot`, `revision`, `version`, `chain`, and `authorityVersion`, paired with server-side dehydration checks blocking modifications to non-editable fields (`AuthorizationDenied`) and converting `PolicyConflict` / `CatalogConflict` into Filament validation errors.

---

## 2. File-by-File Migration Spec for `proxyma`

### 2.1. New Files to Create in `proxyma`
1. **`packman.json`** (root): `name: "axiomasoft/proxyma-monorepo"`, `profile: "laravel-package"`, `packages: ["packages/core", "packages/filament"]`, `phpstanLevel: 9`, `minCoverage: 90`.
2. **`packages/core/src/Configuration/ProxymaOptions.php`** (+ sub-options `QueueOptions.php`, `HealthOptions.php`, `TlsOptions.php`, `MaintenanceOptions.php`, `ExtensionsOptions.php`):
   - Immutable DTOs implementing `Packman\Config\Contracts\OptionsFactory` (`fromArray()`), replacing 30+ raw `$app['config']->get(...)` lookups across `Proxyma`.
   - Typed properties: `TablePrefix $tablePrefix`, `string $connection`, `string $defaultTenant`, `SecretRef $wireDigestKey`, and sub-option DTOs.
3. **`packages/core/src/Diagnostics/ProxymaDoctorCheck.php`**:
   - Implements `Packman\Doctor\Contracts\DoctorCheck` (checks PG connection, schema capability tables, cURL HTTPS proxy features via `TransportDoctor`, queue limits, `wireDigestKey` length).
4. **`packages/core/src/Infrastructure/Database/ProxymaSchemaSets.php`**:
   - Implements `Packman\Schema\Contracts\SchemaSetProvider` registering `SchemaSet('proxyma', SchemaScope::Central, SchemaLifecycle::Platform)` for `packages/core/database/migrations`.
5. **Four Focused Registrars (`packages/core/src/Laravel/Registrars/`):**
   - `ProxymaAdmissionRegistrar.php`
   - `ProxymaCatalogRegistrar.php`
   - `ProxymaExecutionRegistrar.php`
   - `ProxymaPolicyRegistrar.php`
   *(Decomposes the 314-line `ProxymaServiceProvider` to conform to `ProviderSizeGate` <= 80 lines.)*
6. **`packages/core/resources/contracts/php-api.json`**: Generated API snapshot enforcing `#[Api]` and `#[Spi]`.

### 2.2. Files to Modify in `proxyma`
- **Root `composer.json`**: Rename `"axiomasoft/proxyma-workspace"` -> `"axiomasoft/proxyma-monorepo"`; add `require-dev`: `packman-devtools`, `packman-testkit`; align scripts (`lint`, `analyse`, `test`, `gate`, `php-api`).
- **`packages/core/composer.json`**: Require `axiomasoft/packman`, `packman-plugins`, `packman-panels`, `packman-schema`.
- **`packages/core/src/Laravel/ProxymaServiceProvider.php`**: Shrink from **314 lines to < 60 lines** using `Packman\Foundation\Concerns\RegistersPackageRegistrars`, registering `ProxymaOptions` singleton, `ProxymaSchemaSets`, and the 4 registrars; remove raw `loadMigrationsFrom` and raw `config()->get()` calls.
- **`packages/core/src/Domain/Policy/PanelDefinition.php` & `Domain/Panels/{PanelSlot,PanelFormData}.php`**: Annotate with `Packman\Attributes\Api` and inherit/align with `Packman\Panels\Data\{PanelDescriptor,PanelSlot,PanelFormData}`.
- **`packages/core/src/Application/Diagnostics/Doctor.php`**: Delegate checks to `ProxymaDoctorCheck` / `Packman\Doctor`.
- **`packages/filament/composer.json` & `src/ProxymaPlugin.php` & `src/BindingEditor.php`**: Require `axiomasoft/packman-filament`; `ProxymaPlugin` implements `Packman\Filament\Contracts\FilamentPanelPlugin`; `BindingEditor` consumes shared encrypted-context helper from `Packman\Filament\Forms\BindingEditor`.

### 2.3. VCS & AI Cleanup in `proxyma`
- Remove **606 vendored skill files** committed under `.agents/skills/` and replace with plugin-connected skills via `packman ai:sync`.
