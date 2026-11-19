---
name: packman-extensions
description: Build and compose Packman plugins (ExtensionPlugin), platform modules (ModuleDefinition), and Packman\Extensions With* facets.
---

# Packman Extensions, Plugins & Modules

## Decision Rule

- **Stateless intra-package plugin (`axiomasoft/packman-plugins`):**
  - Implement `Packman\Plugins\Contracts\Registry\ExtensionPlugin` (`manifest(): PluginManifest`, `register(PluginRegistrar $registrar): void`).
  - Zero required constructor parameters, zero I/O, zero DB dependency; `PluginGraphCompiler` enforces Kahn topological order, ancestor ownership, and seals `FrozenContributions`.
  - Use `packman.disabled_plugins` for disabled IDs. Host and package implementations of `PluginActivationGate` can add policy.
- **Compiled platform module (`axiomasoft/packman-modules`):**
  - Implement `Packman\Modules\Contracts\Definition\ModuleDefinition` (`manifest(): ModuleManifest`).
  - Discovered from `vendor/composer/installed.json` via `ModuleDiscovery` (`ModulesOptions::$packageTypes` and `$extraKeys`).
  - Use a host `ModuleStateReader` for code-only activation. Add a table prefix for entity declarations and opt into database settings separately.
  - Compiled deterministically through `PipelineStages::ORDER` (`Entity -> EntityField -> Permissions -> Api -> Filament -> Menu -> Routes -> Workflow`) into `bootstrap/cache/modules.php`, with tenant lifecycle tables `mod_modules`, `mod_records`, and `mod_lifecycle_log`.

## Composable `With*` Facets (`Packman\Extensions`)

Compose facets uniformly on both plugins and modules; `ExtensionInspector::inspect()` hydrates `ExtensionFacetProfile`:

- `Configuration\WithConfiguration` + `Configurable`
- `Configuration\WithDatabaseSettings` + `InteractsWithDatabaseSettings`
- `Persistence\WithDatabaseRegistration` + `RegistersInDatabase`
- `Persistence\WithSchema` + `OwnsSchema`
- `Composition\WithCapabilities` + `ProvidesCapabilities`
- `Composition\WithSlots` + `ContributesToSlots`
- `Composition\WithPanels` + `ProvidesPanels`
- `Runtime\WithLifecycleHooks` + `HasLifecycleHooks`
- `Runtime\WithDiagnostics` + `ProvidesDiagnostics`
- `Runtime\WithFeatureFlags` + `DeclaresFeatureFlags`
