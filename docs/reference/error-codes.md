# Error Codes Reference

Packman standardizes package diagnostics on backed `Packman\Errors\Contracts\ErrorCode` enums, `ErrorDescriptor`, `CodedException`, `ProblemDefinition`, and `CompiledProblemRegistry` with RFC 9457 URN prefix `urn:packman:problem:`.

Each package's generated machine-readable catalog lives at `packages/<pkg>/resources/contracts/error-codes.json` (`schema: "packman-error-codes/1"`) and is verified by `ErrorRegistryGate` (`./bin/php84 composer error-codes`).

---

## 1. Error Categories & Unavailability Reasons

- **`Packman\Errors\Enums\ErrorCategory`:** `authentication`, `authorization`, `validation`, `conflict`, `not_found`, `rate_limit`, `unsupported`, `unavailable`, `internal`.
- **`Packman\Errors\Enums\UnavailabilityReason`:** `capability_unavailable`, `capability_disabled`, `operation_unsupported`, `access_denied`, `feature_not_entitled`.
- **`ErrorDescriptor` constraints:** lowercase dotted snake_case wire code (`<= 120` bytes), valid RFC 6901 JSON Pointer `path` (or `null`), and bounded JSON scalar/flat `details` (`<= 4096` bytes via `ErrorDescriptor::MAX_DETAIL_BYTES`).

---

## 2. `axiomasoft/packman` — `CoreErrorCode` (`Packman\Errors\Enums\CoreErrorCode`)

Source catalog: [`packages/core/resources/contracts/error-codes.json`](../../packages/core/resources/contracts/error-codes.json) (`17` cases).

| Case | Wire Code | Problem Type URN | HTTP | Category | Retryable | Title |
|:--|:--|:--|--:|:--|:--|:--|
| `DuplicateCode` | `packman.duplicate_code` | `urn:packman:problem:packman.duplicate_code` | `500` | `internal` | `false` | Duplicate error code. |
| `DuplicateType` | `packman.duplicate_type` | `urn:packman:problem:packman.duplicate_type` | `500` | `internal` | `false` | Duplicate problem type. |
| `ForeignPrefix` | `packman.foreign_prefix` | `urn:packman:problem:packman.foreign_prefix` | `500` | `internal` | `false` | Foreign error prefix. |
| `InternalError` | `packman.internal_error` | `urn:packman:problem:packman.internal_error` | `500` | `internal` | `false` | Internal error. |
| `InvalidConfiguration` | `packman.invalid_configuration` | `urn:packman:problem:packman.invalid_configuration` | `500` | `internal` | `false` | Invalid configuration. |
| `InvalidDefinition` | `packman.invalid_definition` | `urn:packman:problem:packman.invalid_definition` | `500` | `internal` | `false` | Invalid error definition. |
| `InvalidScopePayload` | `packman.invalid_scope_payload` | `urn:packman:problem:packman.invalid_scope_payload` | `422` | `validation` | `false` | Invalid scope payload. |
| `InvalidStatus` | `packman.invalid_status` | `urn:packman:problem:packman.invalid_status` | `500` | `internal` | `false` | Invalid error status. |
| `RegistryFrozen` | `packman.registry_frozen` | `urn:packman:problem:packman.registry_frozen` | `500` | `internal` | `false` | Problem registry is frozen. |
| `SchemaDuplicateMigration` | `packman.schema_duplicate_migration` | `urn:packman:problem:packman.schema_duplicate_migration` | `500` | `internal` | `false` | Duplicate schema migration. |
| `SchemaDuplicateSet` | `packman.schema_duplicate_set` | `urn:packman:problem:packman.schema_duplicate_set` | `500` | `internal` | `false` | Duplicate schema set. |
| `SchemaInvalidSet` | `packman.schema_invalid_set` | `urn:packman:problem:packman.schema_invalid_set` | `500` | `internal` | `false` | Schema set is invalid. |
| `SchemaUnknownSet` | `packman.schema_unknown_set` | `urn:packman:problem:packman.schema_unknown_set` | `404` | `not_found` | `false` | Unknown schema set. |
| `SchemaUnsupportedDriver` | `packman.schema_unsupported_driver` | `urn:packman:problem:packman.schema_unsupported_driver` | `422` | `unsupported` | `false` | Unsupported database driver. |
| `ScopeUnavailable` | `packman.scope_unavailable` | `urn:packman:problem:packman.scope_unavailable` | `409` | `conflict` | `false` | Scope context unavailable. |
| `UnknownCode` | `packman.unknown_code` | `urn:packman:problem:packman.unknown_code` | `500` | `internal` | `false` | Unknown error code. |
| `UnsupportedScope` | `packman.unsupported_scope` | `urn:packman:problem:packman.unsupported_scope` | `422` | `unsupported` | `false` | Unsupported scope. |

---

## 3. `axiomasoft/packman-plugins` — `PluginErrorCode` (`Packman\Plugins\Enums\PluginErrorCode`)

Source catalog: [`packages/plugins/resources/contracts/error-codes.json`](../../packages/plugins/resources/contracts/error-codes.json) (`4` cases).

| Case | Wire Code |
|:--|:--|
| `InvalidGraph` | `plugins.invalid_graph` |
| `MissingExtension` | `plugins.missing_extension` |
| `RegistryFrozen` | `plugins.registry_frozen` |
| `UnsupportedContribution` | `plugins.unsupported_contribution` |

---

## 4. `axiomasoft/packman-modules` — `ModuleErrorCode` (`Packman\Modules\Enums\ModuleErrorCode`)

Source catalog: [`packages/modules/resources/contracts/error-codes.json`](../../packages/modules/resources/contracts/error-codes.json) (`13` cases).

| Case | Wire Code |
|:--|:--|
| `CompilationException` | `modules.compilation_failed` |
| `ModuleEnableFailedException` | `modules.enable_failed` |
| `EntityNotFound` | `modules.entity_not_found` |
| `HasEnabledDependentsException` | `modules.has_enabled_dependents` |
| `InvalidManifestException` | `modules.invalid_manifest` |
| `InvalidRegistryPoint` | `modules.invalid_registry_point` |
| `InvalidStateTransitionException` | `modules.invalid_state_transition` |
| `MissingDependencyException` | `modules.missing_dependency` |
| `ModuleNotFound` | `modules.module_not_found` |
| `RegistryFrozen` | `modules.registry_frozen` |
| `RegistryInputUnavailable` | `modules.registry_input_unavailable` |
| `StaleRegistryRevision` | `modules.stale_registry_revision` |
| `ModuleUpgradeFailedException` | `modules.upgrade_failed` |

---

## 5. `axiomasoft/packman-panels` — `PanelErrorCode` (`Packman\Panels\Enums\PanelErrorCode`)

Source catalog: [`packages/panels/resources/contracts/error-codes.json`](../../packages/panels/resources/contracts/error-codes.json) (`20` cases; provider: `Packman\Panels\Internal\Admission\PanelsProblemDefinitions`).

| Case | Wire Code | Problem Type URN | HTTP | Category | Retryable | Title |
|:--|:--|:--|--:|:--|:--|:--|
| `DefaultPanelConflict` | `panels.default_panel_conflict` | `urn:packman:problem:panels.default_panel_conflict` | `409` | `conflict` | `false` | Conflicting default panels. |
| `DefinitionError` | `panels.definition_error` | `urn:packman:problem:panels.definition_error` | `422` | `validation` | `false` | Invalid panel definition. |
| `DuplicatePanel` | `panels.duplicate_panel` | `urn:packman:problem:panels.duplicate_panel` | `409` | `conflict` | `false` | Duplicate panel registration. |
| `DuplicatePermission` | `panels.duplicate_permission` | `urn:packman:problem:panels.duplicate_permission` | `409` | `conflict` | `false` | Duplicate panel permission. |
| `DuplicateRole` | `panels.duplicate_role` | `urn:packman:problem:panels.duplicate_role` | `409` | `conflict` | `false` | Duplicate panel role. |
| `InvalidConfiguration` | `panels.invalid_configuration` | `urn:packman:problem:panels.invalid_configuration` | `500` | `internal` | `false` | Invalid panels configuration. |
| `InvalidPanelId` | `panels.invalid_panel_id` | `urn:packman:problem:panels.invalid_panel_id` | `422` | `validation` | `false` | Invalid panel identifier. |
| `InvalidPermissionKey` | `panels.invalid_permission_key` | `urn:packman:problem:panels.invalid_permission_key` | `422` | `validation` | `false` | Invalid permission key. |
| `InvalidSavedView` | `panels.invalid_saved_view` | `urn:packman:problem:panels.invalid_saved_view` | `422` | `validation` | `false` | Invalid saved view input. |
| `PluginConflict` | `panels.plugin_conflict` | `urn:packman:problem:panels.plugin_conflict` | `409` | `conflict` | `false` | Conflicting panel plugin settings. |
| `PolicyConflict` | `panels.policy_conflict` | `urn:packman:problem:panels.policy_conflict` | `409` | `conflict` | `true` | Panel policy conflict. |
| `PrefixConflict` | `panels.prefix_conflict` | `urn:packman:problem:panels.prefix_conflict` | `409` | `conflict` | `false` | Conflicting panel resource prefixes. |
| `RegistryFrozen` | `panels.registry_frozen` | `urn:packman:problem:panels.registry_frozen` | `409` | `conflict` | `false` | Panel registry is frozen. |
| `SavedViewAccessDenied` | `panels.saved_view_access_denied` | `urn:packman:problem:panels.saved_view_access_denied` | `403` | `authorization` | `false` | Saved view action denied. |
| `SavedViewConflict` | `panels.saved_view_conflict` | `urn:packman:problem:panels.saved_view_conflict` | `409` | `conflict` | `false` | Saved view revision conflict. |
| `SavedViewNotFound` | `panels.saved_view_not_found` | `urn:packman:problem:panels.saved_view_not_found` | `404` | `not_found` | `false` | Saved view not found. |
| `SubjectResolutionFailed` | `panels.subject_resolution_failed` | `urn:packman:problem:panels.subject_resolution_failed` | `409` | `conflict` | `false` | Subject chain resolution failed. |
| `UnknownPanel` | `panels.unknown_panel` | `urn:packman:problem:panels.unknown_panel` | `404` | `not_found` | `false` | Unknown panel. |
| `UnknownPermission` | `panels.unknown_permission` | `urn:packman:problem:panels.unknown_permission` | `404` | `not_found` | `false` | Unknown panel permission. |
| `UnknownSlot` | `panels.unknown_slot` | `urn:packman:problem:panels.unknown_slot` | `404` | `not_found` | `false` | Unknown panel slot. |

---

## 6. `axiomasoft/packman-filament` — `FilamentErrorCode` (`Packman\Filament\Enums\FilamentErrorCode`)

Source catalog: [`packages/filament/resources/contracts/error-codes.json`](../../packages/filament/resources/contracts/error-codes.json) (`7` cases; provider: `Packman\Filament\Internal\Errors\FilamentProblemDefinitions`).

| Case | Wire Code | Problem Type URN | HTTP | Category | Retryable | Title |
|:--|:--|:--|--:|:--|:--|:--|
| `AuthorizationDenied` | `filament.authorization_denied` | `urn:packman:problem:filament.authorization_denied` | `403` | `authorization` | `false` | Filament action authorization denied. |
| `InvalidConfiguration` | `filament.invalid_configuration` | `urn:packman:problem:filament.invalid_configuration` | `500` | `internal` | `false` | Invalid Filament plugin configuration. |
| `InvalidContext` | `filament.invalid_context` | `urn:packman:problem:filament.invalid_context` | `403` | `authorization` | `false` | Invalid or tampered Filament context. |
| `NonEditableField` | `filament.non_editable_field` | `urn:packman:problem:filament.non_editable_field` | `403` | `authorization` | `false` | Field cannot be edited. |
| `PolicyConflict` | `filament.policy_conflict` | `urn:packman:problem:filament.policy_conflict` | `409` | `conflict` | `true` | Binding policy state conflict. |
| `SurfaceRefused` | `filament.surface_refused` | `urn:packman:problem:filament.surface_refused` | `403` | `authorization` | `false` | Filament surface access refused. |
| `ValidationFailed` | `filament.validation_failed` | `urn:packman:problem:filament.validation_failed` | `422` | `validation` | `false` | Invalid binding submission. |
