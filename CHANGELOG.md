# Changelog

All notable changes to `axiomasoft/packman-monorepo` will be documented in this file.

## Unreleased

- Run GitHub Actions for static analysis, quality gates, contract manifests, SQLite tests, PostgreSQL tests, consumer fixtures, and Composer advisories. Dependabot is not used.
- Reuse parsed sources across quality gates, fail closed on a partial definitions cache or a corrupt module registry, and reload a changed module registry when OPcache is not checking timestamps.
- Resolve each source file's names once for the surface and error scanners, and decode an unchanged `installed.json` once per process.
- Accept libpq environment variables and a bounded connect timeout in the PostgreSQL test lane.
- Run SQLite tests in parallel, and install a consumer fixture from its lockfile when one is present.
- Record the repository homepage, issue tracker, and security policy on every package manifest and in the generated Composer feed.
- Compare pending migrations by their full filename, keep `JsonDocumentCast` reads and writes on the same set of values, and document every publishable package in the installation guide.
- Reject an invalid `modules:repair` name, disagreeing `modules:why` tenant options, and a non-positive `packman:doctor --sample`. An empty schema batch no longer spreads into `array_merge`.
- Keep config validation alive with no report sections, reject unsafe logical table names, treat a blank schema `--database` as the default connection, and fail `modules:compile` cleanly when the registry file cannot be written.
- Fail quality gates on a broken package `composer.json`, and report a definitions-cache directory that cannot be created.
- Refuse a scaffold file that resolves outside its destination directory, and a stub path that leaves the stub root.
- Deny panel resource access for a child-scope witness, require a scope in required mode, fail closed on a missing entry permission, and check `view_any` before listing records.
- Read the enabled module edition from the tenant row, include `database/migrations` in doctor, replace default lists with an explicit empty list, and reject integers that do not fit the platform range.

## 0.1.2

- Discover plugin manifests before inspecting active facets or registering contributions. Applications can supply a configured instance of the declared class while preserving its manifest.
- Accept repeat registration of a known plugin or gate after compilation. A failed compilation leaves the catalog open to correction.
- Register console commands on the owning application and require the active Laravel host to own a schema migration before writing its journal or running DDL.
- Compile explicit entity capability declarations into module registries.
- Enforce internal boundaries for attributed types outside internal namespaces and require an explicit surface attribute on internal declarations.
- Allow an explicit internal owner for cooperating local product subpackages, while keeping external dependencies, layers and runtime/dev boundaries separate.
- Document package/host ownership, lifecycle timing and preserved product constraints with real consumer examples.
- Verify archived real Laravel consumers locally, including simultaneous hosts, Corex Media/Vaulter deduplication and old-data readback after an existing application's update.
- Distribute all eight packages through the public versioned Composer repository, with verified autoload, executable and PHPStan paths inside the existing monorepository archive.

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
