# Changelog

All notable changes to `axiomasoft/packman-monorepo` will be documented in this file.

## Unreleased

- Discover plugin manifests before inspecting active facets or registering contributions. Applications can supply a configured instance of the declared class while preserving its manifest.
- Accept repeat registration of a known plugin or gate after compilation. A failed compilation leaves the catalog open to correction.
- Register console commands on the owning application and require the active Laravel host to own a schema migration before writing its journal or running DDL.

## 0.1.1

- Register built-in plugins on an explicit container with `ExtensionRegistrar::builtInFor()`.
- Compile code-only modules without a table prefix. Module discovery and activation can use application-provided implementations. Versioned state readers support caching. State readers and cache stores resolve within the current application scope when activation is read.
- Extend the standard Composer discovery rules using public constants. Scoped configuration replaces discovery lists in full.
- Expose builders for menu groups, Filament pages, widgets and tabs, web routes, workflow steps and scoped settings in the public module API.
- Scaffold code-only plugins and modules with `make --minimal` in the consuming Composer project.
- Compose package activation policies with the host’s gate, and collect a plugin’s declarations through its public registrar.
- Scope schema ownership and install fingerprints by package, and declare prerequisite schema sets for ordered migrations.
- Resolve dependency graphs using the kernel’s stable topological order.
- Generate valid API manifests for packages with an empty public surface.
- Apply host and package activation policies before registering plugin options and diagnostics.
- Check the database targets exposed by each supported Laravel version in Testkit.

## 0.1.0

- Initial 8-package foundation release (`core`, `modules`, `plugins`, `schema`, `panels`, `filament`, `testkit`, `devtools`).
