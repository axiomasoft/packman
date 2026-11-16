# Configuration Reference

Packman splits configuration into **workspace governance manifests** (`packman.json`, `schemas/`, `split.json`, `compatibility.json`) and **compiled per-package runtime options** (`config/*.php` -> `OptionsFactory` + `ConfigSection` -> `resources/schemas/config.schema.json`).

---

## 1. Root Governance Manifests

### `packman.json` (`schemas/packman.schema.json`)

- `schema_version`: `1`
- `name`: monorepo Composer name (`^[a-z0-9-]+/[a-z0-9-]+$`)
- `profile`: `php-library` | `laravel-package` | `modular-platform` | `product`
- `php`: minimum PHP constraint (e.g., `^8.4`)
- `phpstan_level`: e.g., `max`
- `provider_max_lines`: maximum lines allowed per `*ServiceProvider.php` (`80`)
- `packages[]`: `{ name, path, namespace, profile, layer (L1..L4), type, dev_only?, owns? }`
- `gates`: `{ strict_ownership, provider_size_limit, disallow_internal_cross_imports, require_api_manifest, error_urn_prefix }`

### `split.json` & `compatibility.json`

- [`split.json`](../../split.json): maps each monorepo package directory (`packages/core`, `packages/modules`, etc.) to `{ "repo": ..., "name": ... }` for `MonorepoSplitter`.
- [`compatibility.json`](../../compatibility.json): declares `"schema": "urn:packman:compatibility:1"`, `"major": 1`, and the list of `php-api.json` manifests verified by `CompatibilityMatrixChecker`.

---

## 2. Kernel Config Primitives (`Packman\Config\`)

- **`OptionsFactory`:** Packages compile config arrays once via `fromArray(array $config, string $path): self` and inject the readonly options object rather than calling `config()` / `env()`.
- **`ConfigSections` & `SectionCompiler`:** Sections claim root keys via `ConfigSections::own($root, $validator)` during `register()`. `SectionCompiler` compiles typed `ConfigSection` objects via `JournalingConfigReader`, rejects unknown keys with Levenshtein typo suggestions, and blocks `unsafeBool()` settings in production unless `packman.unsafe.allow_in_production` (`PACKMAN_ALLOW_UNSAFE=true`) is set.
- **`DefinitionsCache` & `DefinitionsCodec`:** Caches compiled definitions with SHA-256 input fingerprinting, round-trip codec verification, atomic `tempnam`/`chmod`/`rename`, and `opcache_invalidate`.
- **`SecretRef` & `TablePrefix`:** `SecretRef` redacts sensitive values to `[redacted]` in `__toString()`, `__debugInfo()`, `jsonSerialize()`, and refuses PHP `serialize()`; `TablePrefix` enforces `/\A[a-z][a-z0-9]{1,6}_\z/`.

---

## 3. Package Configuration Files

### `axiomasoft/packman` — [`packages/core/config/packman.php`](../../packages/core/config/packman.php)

Published tag: `packman-config`. Key root: `packman`.

| Key | Default | Env / Notes |
|:--|:--|:--|
| `packman.unsafe.allow_in_production` | `false` | `PACKMAN_ALLOW_UNSAFE` (`bool`). Permits `unsafeBool()` sections in production. |
| `packman.errors.urn_prefix` | `'urn:packman:problem:'` | Base URN prefix used when compiling `ProblemDefinition` entries. |
| `packman.acknowledged_dangerous_modes` | `[]` | List of dotted config paths acknowledged by operators in `packman:config:validate`. |

### `axiomasoft/packman-schema` — [`packages/schema/config/packman-schema.php`](../../packages/schema/config/packman-schema.php)

Merged under key `packman.schema` by `SchemaServiceProvider`.

| Key | Default | Description |
|:--|:--|:--|
| `packman.schema.connection` | `null` | Default DB connection name used by `TableNames`. |
| `packman.schema.table_prefix` | `'pmn_'` | Physical table prefix for `TableNames`. |
| `packman.schema.module_prerequisite_set` | `'axiomasoft/packman-modules:tenant'` | Prerequisite `SchemaSet` ID before running module-scoped migrations (`null` or empty to disable). |

### `axiomasoft/packman-plugins` — [`packages/plugins/config/packman-plugins.php`](../../packages/plugins/config/packman-plugins.php)

Merged under `packman.plugins`.

| Key | Default | Description |
|:--|:--|:--|
| `packman.plugins` | `[]` | List of `ExtensionPlugin` class-strings compiled by `PluginGraphCompiler`. |
| `packman.disabled_plugins` | `[]` | List of `vendor.name` plugin IDs disabled without database state (`ExtensionRegistrar`). |

### `axiomasoft/packman-modules` — [`packages/modules/config/modules.php`](../../packages/modules/config/modules.php)

Published tag: `packman-modules-config`. Merged under `modules` (host apps may also override keys under `packman.modules`, merged via `array_replace_recursive`). Validated by `ModulesOptions::fromArray()`.

| Key | Default | Env / Notes |
|:--|:--|:--|
| `modules.connection` | `null` | `PACKMAN_MODULES_CONNECTION` |
| `modules.cache.store` | `null` | `PACKMAN_MODULES_CACHE_STORE` |
| `modules.cache.ttl` | `3600` | `PACKMAN_MODULES_CACHE_TTL` (positive int) |
| `modules.cache.prefix` | `'modules'` | `PACKMAN_MODULES_CACHE_PREFIX` |
| `modules.package_types` | `['packman-module', 'packman-plugin', 'module', 'plugin']` | Composer `type` values scanned in `installed.json` by `ModuleDiscovery`. |
| `modules.extra_keys` | `[['packman', 'module'], ['packman', 'plugin'], ['module'], ['plugin']]` | JSON key paths inside Composer `extra` pointing to `ModuleDefinition` class names. |
| `modules.cache_path` | `'bootstrap/cache/modules.php'` | Atomic OPcache compiled registry path relative to `basePath()`. |
| `modules.lock_key` | `'modules:lifecycle-graph'` | Lifecycle lock identifier passed to `DatabaseModuleLifecycle`. |
| `modules.compiler_package` | `'axiomasoft/packman-modules'` | Compiler package identity stamped into `CompiledRegistry`. |
| `modules.tables.modules` | `'mod_modules'` | Module state table name. |
| `modules.tables.records` | `'mod_records'` | Entity ownership claim table name. |
| `modules.tables.lifecycle_log` | `'mod_lifecycle_log'` | Monotonic transition journal table name. |
| `modules.registry_schema` | `'packman-registry/1'` | Compiled registry schema marker. |
| `modules.compile_command` | `'modules:compile'` | Artisan command name registered for compilation and `php artisan optimize`. |

### `axiomasoft/packman-panels` — [`packages/panels/config/packman-panels.php`](../../packages/panels/config/packman-panels.php)

Published tag: `packman-panels-config`. Merged under `packman-panels` (host apps may also override under `packman.panels`, merged via `array_replace_recursive`). Validated by `PanelsOptions::fromArray()`.

| Key | Default | Env / Notes |
|:--|:--|:--|
| `packman-panels.provider_tag` | `'packman.panels.providers'` | Container tag collecting `Contracts\Registry\PanelProvider` services. |
| `packman-panels.views.connection` | `null` | `PACKMAN_PANELS_VIEWS_CONNECTION` |
| `packman-panels.views.table` | `'sys_saved_views'` | Table storing saved views. |
| `packman-panels.views.scopes_table` | `'sys_saved_view_scopes'` | Table storing saved view scopes. |
| `packman-panels.defaults.resource_prefix` | `true` | Boolean or non-empty string prefix rule. |
| `packman-panels.defaults.cache.store` | `null` | Cache store name (`string|null`). |
| `packman-panels.defaults.cache.ttl` | `300` | Positive integer TTL or `null`. |
| `packman-panels.defaults.cache.generation` | `1` | Positive integer cache generation. |
| `packman-panels.defaults.consistency.reads` | `'primary'` | `'primary'` or `'default'`. |
| `packman-panels.defaults.consistency.state_refresh` | `'request'` | `'request'` or `'check'`. |
| `packman-panels.defaults.trace_decisions` | `false` | Enables decision tracing in compiled panels. |

### `axiomasoft/packman-filament` — [`packages/filament/config/packman-filament.php`](../../packages/filament/config/packman-filament.php)

Published tag: `packman-filament-config`. Merged under `packman-filament`. Validated by `FilamentOptions::fromArray()`.

| Key | Default | Description |
|:--|:--|:--|
| `packman-filament.navigation_group` | `'Packman'` | Default Filament navigation group label. |
| `packman-filament.guard_panel` | `null` | Default headless Packman panel ID used by `PackmanFilamentPlugin`. |
| `packman-filament.enforce` | `false` | Enables strict `AuthorizesResource`/`Page`/`RelationManager`/`Widget` trait enforcement on boot. |
| `packman-filament.resources` | `[]` | Associative map `<key> => bool` gating candidate resources added via `PackmanFilamentPlugin::resource()`. |
| `packman-filament.abilities` | `['view_any', 'view', 'create', 'update', 'delete', 'delete_any', 'restore', 'restore_any', 'force_delete', 'force_delete_any', 'replicate', 'reorder']` | Snake-case Filament abilities enforced by `FilamentGate`. |
| `packman-filament.exclude` | `['resources' => [], 'pages' => [], 'widgets' => []]` | Class lists excluded from surface enforcement. |
| `packman-filament.persistent_middleware` | `[]` | Additional Livewire persistent middleware classes/aliases. |
| `packman-filament.refuse_unverifiable_downloads` | `true` | Binds `RefusesUnverifiableDownload` over Filament's `DownloadExport`. |
