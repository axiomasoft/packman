# Project Migration Spec 07: `tenant`

**Repository Path:** `/home/vostrikov/projects/packages/tenant`  
**Target Profiles:**
- `packages/tenant` (`axiomasoft/tenant`): **`php-library`** (100% pure PHP `>=8.4 <8.6` + `ext-intl`, **zero Laravel dependency**)
- `packages/tenant-laravel` (`axiomasoft/tenant-laravel`): **`laravel-package`**
- `packages/tenant-postgres` (`axiomasoft/tenant-postgres`): **`laravel-package`**
**Zero Host-Product Coupling:** Purges 2 hardcoded legacy host-template cryptographic/lock strings and 1 docblock reference found in audit.

---

## 1. Best Practices Extracted from `tenant` into `packman`

1. **`php-library` Profile Standard (from `packages/tenant`):**
   - Pure PHP 8.4+ contracts (`ExtensionDecoder`, `ExtensionRef`), zero Illuminate/container leakage; proved compatibility of the pure-PHP surface inside `axiomasoft/packman` (`packages/core`).
2. **Machine-Readable Contract Specs (from `contracts/*.v1.json`):**
   - Explicit error taxonomy (`errors.v1.json`: 36 error conditions categorized by `phase`, `stateEffect` `preserve|poison`, `meaning`) that shaped `Packman\Errors\{Contracts/DescribesError,Data/ErrorDescriptor,Data/ProblemDefinition}`.
3. **Guarded Runtime Facility Isolation (from `packages/tenant-laravel`):**
   - `GuardedConnection`, `GuardedCache`, `GuardedSessionHandler`, HMAC-signed `QueueRegistry` payload context reconstitution, and `protectBinding()` singleton lock pattern.

---

## 2. Exact File-by-File Migration Spec for `tenant`

### 2.1. Root Monorepo Fixes
1. **`composer.json`**:
   - Rename `"axiomasoft/tenant-workspace"` -> `"axiomasoft/tenant-monorepo"`.
   - **Remove `"autoload": { "psr-4": { "Tenant\\": "packages/tenant/src/" } }` from root `composer.json`** (each subpackage owns its own autoloading).
   - Add local path repositories for `packages/{tenant,tenant-laravel,tenant-postgres}` and `"require-dev"` for `packman-devtools` & `packman-testkit`.
2. **Create Missing Standard Tooling Files:**
   - `packman.json` (`profile: "laravel-package"`, declaring `packages/tenant` as `php-library` and `tenant-laravel`, `tenant-postgres` as `laravel-package`, `phpstanLevel: 9`, `minCoverage: 95`).
   - `pint.json` & `rector.php` (extending `packman-devtools` base configs — currently missing in `tenant`).
   - Run `packman ai:sync` to install `.claude/rules/*`, `.agents/`, `.codex/agents/*`, `CLAUDE.md`, `AGENTS.md` (currently 0 AI files in `tenant`).

### 2.2. `packages/tenant` (`php-library` — Pure PHP Preserved)
- **`composer.json`**: Add `"axiomasoft/packman": "^0.1"` (using `axiomasoft/packman` pure-PHP kernel surface — **zero Laravel dependency added**).
- **`src/Attributes/{Api,Spi}.php`**: Replace/alias with `Packman\Attributes\{Api,Spi}`.
- **`src/Exceptions/TenantFailure.php`**: Implement `Packman\Errors\Contracts\DescribesError` returning `Packman\Errors\Data\ErrorDescriptor` mapped from `errors.v1.json`.

### 2.3. `packages/tenant-laravel` (`laravel-package`)
- **`composer.json`**: Require `"axiomasoft/packman": "^0.1"`.
- **Create `src/Configuration/TenantOptions.php` & `TenantFacilitiesOptions.php`**:
  - Immutable `final readonly` options (`centralConnection`, `cacheStores`, `centralCacheStores`, `TenantFacilitiesOptions($database, $cache, $session, $storage)`, `storageDriver`, `shared`) implementing `Packman\Config\Contracts\OptionsFactory::fromArray()`, replacing dynamic untyped `FrameworkConfig` queries.
- **Create `src/Diagnostics/TenantDoctorCheck.php`**:
  - Implements `Packman\Doctor\Contracts\DoctorCheck` (checks `ext-intl`, `ext-posix`, valid `central_connection`, `queue.connections.database.after_commit === false`, declared `tenant` cache stores).
- **Create 3 Registrars (`src/Registrars/{TenantRuntimeRegistrar,TenantFacilitiesRegistrar,TenantQueueRegistrar}.php`)**:
  - Implement `Packman\Foundation\Contracts\PackageRegistrar`.
- **`src/TenantServiceProvider.php`**: Refactor 151-line provider to use `RegistersPackageRegistrars` + `TenantOptions`.
- **`src/Internal/FrameworkConfig.php` (Line 10)**: Remove legacy host dynamic configuration seam docblock mention (replace with `Packman dynamic configuration seam` or remove `FrameworkConfig` once fully migrated to `TenantOptions`).

### 2.4. `packages/tenant-postgres` (`laravel-package`)
- **`composer.json`**: Require `axiomasoft/packman`, `axiomasoft/packman-schema`; register `Tenant\Postgres\TenantPostgresServiceProvider`.
- **Create `src/Infrastructure/Database/TenantPostgresSchemaSets.php` & `src/TenantPostgresServiceProvider.php`**:
  - Implement `Packman\Schema\Contracts\SchemaSetProvider` declaring `SchemaSet('tenant-postgres', SchemaScope::Central, SchemaLifecycle::Platform)`.
- **Purge Hardcoded Legacy Template Strings:**
  - **`src/Internal/Database/TemplateManifest.php:63`**: Replace legacy host-template manifest prefix -> `"tenant-template-manifest/v1\0".$payload`.
  - **`src/Internal/Database/TemplateIntegrityLock.php:16`**: Replace legacy host-template integrity lock prefix -> `'tenant-template-integrity:'.$templateId`.
  - **`tests/postgres/*`**: Update corresponding manifest & advisory-lock test expectations.
