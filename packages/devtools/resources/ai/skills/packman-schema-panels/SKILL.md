---
name: packman-schema-panels
description: Author Packman SchemaSets, PackmanMigration files, ConstraintNames, headless PanelProviders, and enforced PackmanFilamentPlugin panels.
---

# Packman Schema, Panels & Filament

## Schema Governance (`Packman\Schema`)

1. Declare migrations via `SchemaSet` (`SchemaScope::Central|Tenant|Dedicated`, `SchemaLifecycle::Platform|Module`, `drivers: ['pgsql', ...]` or `['pgsql', 'sqlite']`) and tag `SchemaSetProvider`.
2. Register owned table nouns in `SchemaOwnershipRegistry` (`WithSchema::tableNouns()`).
3. Extend `PackmanMigration` (`uuidPrimary()`, `identityPrimary()`, `money()`, `ref()`, `timestampsTz()`, `index()`, `unique()`, `foreign()`, `check()`, `exclude()`), using `ConstraintNames::shorten()` for 63-byte PostgreSQL compliance.
4. Keep migration timestamps strictly unique (`SchemaGate`), table names unique across packages (`TableOwnershipGate`), and never write directly to `sys_*`/`mod_*`/`migrations` (`TableWriteOwnershipGate`).

## Panels & Filament (`Packman\Panels`, `Packman\Filament`)

1. Extend `Packman\Panels\Builder\PanelProvider` (registered in `PanelRegistry` before `$app->booted(...)` freeze) or implement `Contracts\Registry\PanelProvider` tagged with `packman.panels.providers`.
2. Understand automatic catalog bridging: `InMemoryPanelCatalogue` standalone vs `ModulePanelCatalogue` when `ModuleRegistry` & `EntityRegistry` are bound.
3. Configure `PackmanFilamentPlugin::make()->guardPanel(...)->enforce()` AFTER `->tenant(...)`, using `AuthorizesResource`, `AuthorizesPage`, `AuthorizesRelationManager`, and `AuthorizesWidget` traits intact and `BindingEditor` for AES-sealed form context.
