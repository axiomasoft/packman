# CLI Commands Reference

All Packman CLI commands should be executed with PHP 8.4 via [`./bin/php84`](../../bin/php84).

---

## 1. Engineering CLI (`bin/packman`)

Entrypoint: [`bin/packman`](../../bin/packman) (also shipped by `axiomasoft/packman-devtools` at `packages/devtools/bin/packman`), wired by `Packman\DevTools\Console\ApplicationFactory`.

| Command | Arguments & Options | Implementation | Purpose |
|:--|:--|:--|:--|
| `gate` | `--only=*` (repeatable or comma-separated gate IDs), `--json`, `--root=<path>` | `GateRunnerCommand` | Run all 16 architecture, boundary, contract, and quality gates against `packman.json`. |
| `php-api` | `--check` (default), `--write`, `--root=<path>` | `PhpApiCommand` | Verify or regenerate `resources/contracts/php-api.json` (`php-api/1`) per package via `ApiManifestGenerator`. |
| `error-codes` | `--check` (default), `--write`, `--root=<path>` | `ErrorCodesCommand` | Verify or regenerate `resources/contracts/error-codes.json` (`packman-error-codes/1`) via `ErrorCodeSchemaGenerator`. |
| `config-schema` | `--check` (default), `--write`, `--root=<path>` | `ConfigSchemaCommand` | Verify or regenerate Draft 2020-12 `resources/schemas/config.schema.json` manifests via `ConfigSchemaGenerator`. |
| `make` | `<profile>` (`php-library`, `laravel-package`, `packman-plugin`, `packman-module`), `<name>`, `--vendor=`, `--dir=packages`, `--force`, `--dry-run`, `--root=<path>` | `MakeCommand` | Scaffold a new package from `packages/devtools/stubs/` via `Layout` and `StubStore`. |
| `split` | `--dry-run`, `--only=*`, `--branch=`, `--root=<path>` | `SplitCommand` | Validate release preflight (`MonorepoSplitter`) and plan/execute `git subtree split` targets declared in `split.json`. |
| `ai:sync` | `--dry-run`, `--root=<path>` | `AiSyncCommand` | Sync `ai/manifest.json`, `ai/rules/*.md`, and `ai/agents/*.md` into `.claude/`, `.codex/agents/*.toml`, `.gemini/`, and `AGENTS.md` via `EcosystemAiSynchronizer`. |

When inspecting another checkout with `--root`, install its Composer dependencies first. The error registry evaluates that checkout's problem definition providers using its Composer mappings, including a configured `vendor-dir`.


---

## 2. Laravel Artisan Runtime Commands

### `axiomasoft/packman` (`packages/core`)

- **`packman:doctor`** (`Packman\Doctor\Laravel\Console\DoctorCommand`)
  - `--production` — Evaluate against production strictness (warnings may fail).
  - `--json` — Emit one `packman.doctor/v1` JSON document.
  - `--prefix=*` / `--check=*` — Filter checks by dotted segment prefix (`DoctorRegistry::matching()`).
  - `--sample=1000` — Positive integer row limit for sampling checks.
  - `--probe-storage` — Allow probing storage disks.
- **`packman:config:validate`** (`Packman\Config\Internal\Commands\ValidateConfigCommand`)
  - `--format=text` — Output format (`text` supported).
  - `--production` — Evaluate `RuntimeConfigGate` checks as production.
  - Validates bound `OptionsFactory` classes via tagged `ConfigReportSectionInterface` contributors and prints redacted secret status (`present` / `absent`).

### `axiomasoft/packman-schema` (`packages/schema`)

- **`packman:schema:migrate`** (`Packman\Schema\Internal\Commands\MigrateSchemaCommand`)
  - `--scope=tenant` (`central` | `tenant` | `dedicated`)
  - `--database=` — Optional target connection (forbidden on `dedicated` sets).
  - `--modules` — Include `SchemaLifecycle::Module` sets (excluded by default).
  - `--force` — Bypass interactive production confirmation (`ConfirmableTrait`).
- **`packman:schema:status`** (`Packman\Schema\Internal\Commands\SchemaStatusCommand`)
  - `--scope=tenant` (`central` | `tenant` | `dedicated`)
  - `--database=` — Optional target connection.
  - Prints table `[set, scope, lifecycle, version, applied, pending, unknown]`; exits `1` when any set has pending or unknown migrations.

### `axiomasoft/packman-plugins` (`packages/plugins`)

- **`packman:plugins`** (`Packman\Plugins\Laravel\Console\ExtensionsCommand`)
  - `--json` — Print machine-readable JSON array of `PluginDescription` records.
  - Otherwise renders `[Plugin, Version, Source, Kind, Key, Class]` table.

### `axiomasoft/packman-modules` (`packages/modules`)

- **`modules:compile`** (`Packman\Modules\Internal\Commands\CompileModulesCommand`)
  - Signature defaults to `modules:compile` and is configurable via `modules.compile_command` (`ModulesOptions::$compileCommand`).
  - Discovers module manifests via `ModuleDiscovery`, compiles via `RegistryCompiler`, and writes `bootstrap/cache/modules.php` atomically (`CompiledModuleRegistry::writeAtomic`). Registered into `php artisan optimize` by `ModulesServiceProvider`.
- **`modules:why`** (`Packman\Modules\Internal\Commands\ExplainModuleCommand`)
  - Argument `module` — Composer package name of the module.
  - `--json` — Emit machine-readable JSON explanation.
  - `--scope=` / `--account=` — Trusted scope identifier for tenant activation verdict.
- **`modules:repair`** (`Packman\Modules\Internal\Commands\RepairModuleCommand`)
  - Argument `module` — Composer package name to reconcile against `mod_records` and reset from `purged` to `available` (`ModuleLifecycle::repair()`).

---

## 3. Root Composer Scripts & Makefile Targets

Defined in [`composer.json`](../../composer.json) and [`Makefile`](../../Makefile):

| Composer Script | Makefile Target | Underlying Command |
|:--|:--|:--|
| `composer lint` | `make lint` | `vendor/bin/pint` |
| `composer lint:check` | `make lint-check` | `vendor/bin/pint --test` |
| `composer analyse` | `make analyse` | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` |
| `composer refactor` | `make refactor` | `vendor/bin/rector process` |
| `composer refactor:check` | `make refactor:check` | `vendor/bin/rector process --dry-run` |
| `composer test` | `make test` | `vendor/bin/pest --exclude-group=pg` |
| `composer test:pg` | `make test:pg` | `vendor/bin/pest --group=pg` |
| `composer test:arch` | `make test-arch` | `vendor/bin/pest --testsuite=Arch` |
| `composer gate` | `make gate` | `@php bin/packman gate` |
| `composer php-api` | `make php-api` | `@php bin/packman php-api --check` |
| `composer error-codes` | `make error-codes` | `@php bin/packman error-codes --check` |
| `composer config-schema` | `make config-schema` | `@php bin/packman config-schema --check` |
| `composer ai:sync` | `make ai-sync` | `@php bin/packman ai:sync --dry-run` |
| — | `make fixtures` | Runs `composer install` and `composer test` inside each `fixtures/*` consumer |
| — | `make ci` | Runs `lint-check analyse refactor-check gate php-api test test-pg fixtures` |
