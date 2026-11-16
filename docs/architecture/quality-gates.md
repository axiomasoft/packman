# Quality Gates, Static Analysis & Test Lanes

Packman enforces architectural, boundary, contract, and code-quality invariants automatically through **`axiomasoft/packman-devtools`** (`bin/packman gate`), PHPStan level `max`, Laravel Pint, Rector, and dual SQLite/PostgreSQL test suites (`axiomasoft/packman-testkit`).

---

## 1. The 16 Architecture & Quality Gates

All 16 gates are registered in `Packman\DevTools\Gates\Runner\GateRunner::default()` and parameterized by [`packman.json`](../../packman.json) and `schemas/profiles/*.profile.json`:

| # | Gate ID | Category | Implementation Class | Enforced Invariant |
|:--|:--|:--|:--|:--|
| 1 | `package-graph` | `architecture` | `PackageGraphGate` | Enforces L1->L2->L3->L4 DAG, zero cycles, zero runtime dependencies on `dev_only` packages, and declared `composer.json` edges for all cross-package imports. |
| 2 | `module-layout` | `architecture` | `ModuleLayoutGate` | Validates `src/` subdirectories, `composer.json` `type`, and `allow_laravel_required` against `schemas/profiles/*.profile.json`. |
| 3 | `ownership` | `architecture` | `OwnershipGate` | Ensures unambiguous PSR-4 namespace prefixes per package and blocks cross-package imports of `*\Internal\*` symbols (`disallow_internal_cross_imports`). |
| 4 | `attributes-architecture` | `architecture` | `AttributesArchitectureGate` | Runs `SurfaceScanner` to require `#[Api]`, `#[Spi]`, or `#[Internal]` on every `src/` type with zero `#[Internal]` leakage in public/SPI signatures. |
| 5 | `config-composition` | `boundaries` | `ConfigCompositionGate` | Blocks ambient `config()`, `env()`, and `Config::*()` calls outside `Config/`, `Laravel/`, `Console/`, `Commands/`, `Testing/`, and `*ServiceProvider.php`. |
| 6 | `runtime-globals` | `boundaries` | `RuntimeGlobalsGate` | Forbids `global` statements, ambient `env()`, and direct superglobal reads (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`, `$_SESSION`, `$_ENV`, `$_SERVER`, `$GLOBALS`), with narrow exemptions for test harness helpers and Laravel CLI `$_SERVER['argv']` probes. |
| 7 | `provider-size` | `boundaries` | `ProviderSizeGate` | Enforces `<= provider_max_lines` (default `80`, `120` for `product`) on every `*ServiceProvider.php`. |
| 8 | `table-ownership` | `boundaries` | `TableOwnershipGate` | Validates `Schema::create()` table identifiers across `database/migrations` and detects cross-package table collisions. |
| 9 | `table-write-ownership` | `boundaries` | `TableWriteOwnershipGate` | Blocks direct `DB::table()` writes (`insert`, `update`, `upsert`, `delete`, `truncate`, etc.) to `migrations`, `sys_*`, and `mod_*` outside `axiomasoft/packman-schema` and `axiomasoft/packman-modules`. |
| 10 | `php-api` | `contracts` | `PhpApiGate` | Runs `ApiManifestGenerator` in check mode to ensure every package requiring an API manifest has a fresh, schema-valid `resources/contracts/php-api.json`. |
| 11 | `error-registry` | `contracts` | `ErrorRegistryGate` | Runs `ErrorSourceScanner` to validate unique `<prefix>.<snake_case>` `ErrorCode` cases, `urn:packman:problem:` URN prefixes, and `ProblemDefinitionProvider` completeness. |
| 12 | `extension-contract` | `contracts` | `ExtensionContractGate` | Validates `composer.json` `extra.packman.module` classes and AST `PluginManifest` / `ModuleManifest::make()` identifiers. |
| 13 | `schema-gate` | `contracts` | `SchemaGate` | Validates `packman.json` against `schemas/packman.schema.json`, JSON syntax under `schemas/` and `packages/*/resources/`, and unique `YYYY_MM_DD_NNNNNN_name.php` migration prefixes. |
| 14 | `package-docs` | `quality` | `PackageDocsGate` | Verifies required `README.md`, `CHANGELOG.md`, and non-empty `composer.json` `description` per profile. |
| 15 | `naming` | `quality` | `NamingGate` | Enforces `declare(strict_types=1);`, kebab-case Composer names, and strict PSR-4 namespace/symbol-to-filename alignment. |
| 16 | `quality-ceiling` | `quality` | `QualityCeilingGuard` | Enforces max `1500` lines per source file and forbids `eval()`, `dd()`, `dump()`, `var_dump()`, `extract()`, `compact()`, and inline `@phpstan-ignore` in non-dev `src/`. |

---

## 2. Static Analysis, Formatting & Refactoring

- **PHPStan (`phpstan.neon`):** Runs at `level: max` with `larastan/larastan`, `phpstan/phpstan-strict-rules`, `bleedingEdge.neon`, `checkModelProperties: true`, `checkPhpDocMissingReturn: true`, and `reportUnmatchedIgnoredErrors: true`.
- **Modules AST Rules (`packages/modules/extension.neon`):** Registers 9 concrete PHPStan rules in `Packman\Modules\PHPStan\Rules\*` covering PostgreSQL pooling safety (`NoSetWithoutLocalRule`, `NoSessionAdvisoryLockRule`, `NoListenNotifyRule`, `NoTempTableRule`, `NoSqlPrepareRule`, `NoEvalRule`) and module boundaries (`NoForeignPrefixSqlRule`, `NoFsOutsideModuleRule`, `NoDirectSystemTableWriteRule`).
- **Pint (`pint.json` & `packages/devtools/config/pint.base.json`):** Enforces `laravel` preset plus `blank_line_before_statement`, `global_namespace_import`, and multiline trailing commas.
- **Rector (`rector.php` & `Packman\DevTools\Rector\PackmanRectorSet`):** Applies PHP 8.4 + Laravel sets alongside custom rules `BoundarySafeStringClassRector`, `DispatchToHelperFunctionsRector`, and `ServerVariableToRequestFacadeRector`.

---

## 3. Dual Database Test Lanes (`Packman\Testkit`)

- **`PackageTestCase` (`packages/testkit/src/PackageTestCase.php`):** Provides `IsolatesDatabasePath`, `UsesSuiteDatabase`, `FreshApplication`, `AssertsRegisteredErrors`, and OpenAPI/JSON-Schema contract assertion helpers.
- **Database Safety (`TestDatabaseGuard`):** Verifies the active PostgreSQL database name ends in `_test` (`SELECT current_database()`) and requires `PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1` before running destructive test operations.
- **Concurrency Testing:** `Packman\Testkit\Concurrency\Process\Concurrency` (`pcntl_fork` + process barriers) and `AdvisoryLockRace` / `PostgresBarrier` test race conditions and advisory lock safety on PostgreSQL (`--group=pg`).
- **Reusable Conformance Traits:** `PluginContractTests` and `ModuleContractTests` validate manifest identifiers, deterministic compilation, `ExtensionContract::verify()`, and `ExtensionInspector` facets.
