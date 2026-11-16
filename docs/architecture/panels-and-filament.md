# Headless Panels & Filament Integration

Packman divides UI governance into two decoupled layers: headless domain panel registration, projection, admission, and saved views in **`axiomasoft/packman-panels`** (`L2`), and Filament 5 + Livewire security/form enforcement in **`axiomasoft/packman-filament`** (`L3`).

---

## 1. Headless Panels (`Packman\Panels\`)

### Two-Phase `PanelRegistry` & `PanelRecipe`

1. **Open Registration Phase (during boot):**
   - `PanelRegistry::register(class-string<PanelProvider>)` registers classes extending `Packman\Panels\Builder\PanelProvider`.
   - `replace(class-string<PanelProvider>)`, `configure(string $id, Closure)`, and `configureAll(Closure)` layer mutations onto `PanelRecipe`.
   - `PanelRecipe` tracks origin precedence (`provider` < `plugin` < `configureAll` < `configure($id)`), enforcing conflict detection (`DuplicatePanelException`, `DefaultPanelConflictException`, `PrefixConflictException`, `PluginConflictException`).
2. **Atomic Freeze Phase (`$app->booted(...)`):**
   - `PanelsBindingsRegistrar::boot()` hydrates extension panels (`WithPanels`) via `PanelExtensionBridge`, then invokes `PanelRegistryImpl::freeze()`.
   - After freezing, any mutation throws `RegistryFrozenException`, making compiled `Panel` descriptors, `PanelCatalog` permission/role catalogs, and `PanelFingerprint` hashes immutable and Octane-safe.

### Standalone vs. Modular Catalogue

- `PanelCatalogue` resolves `InMemoryPanelCatalogue` when `packman-modules` is absent.
- When `ModuleRegistry` and `EntityRegistry` (`axiomasoft/packman-modules`) are both bound, `PanelsBindingsRegistrar` automatically binds `ModulePanelCatalogue` instead.
- Similarly, `SavedViewsBindingsRegistrar` resolves `EntityViewSchemaProvider` when `EntityRegistry` is bound, and falls back to `UnavailableViewSchemaProvider` otherwise.

### Subject Chains, Form Projection & Admission

- **Subjects & Forms:** `SubjectChainResolver` (`PanelSubjects`) builds hierarchical `SubjectChain`s of `SubjectNode` instances. `PanelFormProjector` (`PanelFormProjection`) computes `PanelFormData` with value inheritance, bounds clamping (`clamped`, `minimum`, `maximum`, `clamped_by`), server-side `editable` flags, and optimistic concurrency `editVersion` hashes.
- **Admission:** `PanelAdmitter` (`PanelAdmission`) delegates to `PanelAdmissionGate` (`isMember`, `hasRole`, `childScopesWithRole`, `allowsEntry`) respecting `TenantMode` and `AssignmentScopeMode`, producing `AdmissionResult` and `PanelAccess`.
- **Saved Views:** `SavedViewRepository` (`SqlSavedViewRepository`) stores scoped query configurations in `sys_saved_views` and `sys_saved_view_scopes` (registered via `PanelsSchemaSets`), guarded by `ViewAccessPolicy` and `ViewConfigValidator`.

---

## 2. Filament 5 Security & Forms (`Packman\Filament\`)

### `PackmanFilamentPlugin`

`Packman\Filament\PackmanFilamentPlugin` implements `FilamentPanelPlugin` (`id = 'packman'`):

- **Builder API:** `make()`, `current(FilamentPanel)`, `get(?string $filamentPanel)`, `guardPanel(?string $panel)`, `enforce(bool $enforce = true)`, `abilities(array $abilities)`, `exclude(array $resources, array $pages, array $widgets)`, `persistentMiddleware(array $middleware)`, `resource(string $key, string $resourceClass, bool|Closure $featureEnabled = true)`, and `enableResource(string $key, bool $enabled = true)`.
- **Tenancy Ordering Invariant:** On Filament panels with tenancy, `->tenant(...)` must be called **before** `->plugin(PackmanFilamentPlugin::make())`; calling `->tenant(...)` afterward throws `InvalidBindingStateException` during `boot()`.
- **Enforced Surface Verification:** When `->enforce()` is active, `boot()` verifies via `ClassTraits::keepsMethods()` that:
  - Resources use `AuthorizesResource` without overriding `getAuthorizationResponse` or `getEloquentQuery`.
  - Pages use `AuthorizesPage` without overriding `canAccess`.
  - RelationManagers use `AuthorizesRelationManager` without overriding `canViewForRecord`, `getAuthorizationResponse`, or `makeTable` (and target a resource using `AuthorizesResource`).
  - Widgets use `AuthorizesWidget` without overriding `canView`.

### Security Hooks (`FilamentSecurityRegistrar`)

`FilamentSecurityRegistrar` installs defense-in-depth protections across Filament, Livewire, and Laravel's authorization gate:

- **Livewire Component Boot & Calls:** `RefusesSurfaceBoot` (registered on `ComponentHookRegistry`), `RefusesSurfaceCalls`, `RefusesEditableColumns`, and `AuthorizesActions::beforeCall(...)`.
- **Action & Bulk Authorization:** `AuthorizesActions::configure(...)`, `ActionCalling` listeners (`AuthorizesActions`, `AuthorizesExports`), and per-record bulk authorization (`DeleteBulkAction`, `ForceDeleteBulkAction`, `RestoreBulkAction`) plus `AttachAction`/`AssociateAction` query scoping via `FilamentGate`.
- **Download Protection:** Replaces `DownloadExport` with `RefusesUnverifiableDownload` when `packman-filament.refuse_unverifiable_downloads` is enabled.
- **Persistent Middleware:** Registers `packman.panel` and configured `persistent_middleware` with `LivewireManager::addPersistentMiddleware()`.

### Stateless Encrypted `BindingEditor`

`Packman\Filament\Forms\BindingEditor` bridges Filament forms with `PanelRegistry`, `PanelFormProjector`, and `SubjectChainResolver`:

- `open(...)` projects `PanelFormData`, signs subject, target, panel, slot, revision, `edit_version`, `chain`, and `authority_version` into a JSON payload encrypted via `Crypt::encryptString(...)`.
- `prepareSave(...)` and `save(...)` decrypt the context, verify tenant equality (`hash_equals`), re-resolve the live subject chain, compare `edit_version` and `chain` (throwing `BindingConflictException` on drift), enforce server-side `$field['editable']` before accepting changes (`BindingAuthorizationException::nonEditable()`), and validate `reason` (`required|string|max:512`).
- `schemaComponents(...)` emits Filament `Section` / `Select` / `TextInput` / `Hidden` components pre-wired with field-ID hex encoding (`fieldId()` / `decodeFieldId()`).
