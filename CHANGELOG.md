# Changelog

All notable changes to `axiomasoft/packman-monorepo` will be documented in this file.

## 0.1.1

- Register built-in plugins on an explicit container with `ExtensionRegistrar::builtInFor()`.
- Compile code-only modules without a table prefix. Module discovery and activation can use application-provided implementations. Versioned state readers support caching.
- Extend the standard Composer discovery rules using public constants. Scoped configuration replaces discovery lists in full.
- Expose builders for menu groups, Filament pages, widgets and tabs, web routes, workflow steps and scoped settings in the public module API.
- Generate valid API manifests for packages with an empty public surface.

## 0.1.0

- Initial 8-package foundation release (`core`, `modules`, `plugins`, `schema`, `panels`, `filament`, `testkit`, `devtools`).
