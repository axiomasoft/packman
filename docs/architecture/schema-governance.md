# Schema Governance, Ownership & Migrations

`axiomasoft/packman-schema` (`packages/schema`, `Packman\Schema\`) governs multi-scope migrations, logical-to-physical table mapping, PostgreSQL identifier sizing, and pre-DDL collision detection.

---

## 1. Schema Sets & Scoped Migration Execution

Packages never call Laravel's implicit `loadMigrationsFrom()`. Instead, each owning package tags a `SchemaSetProvider` under `SchemaSetProvider::class` returning one or more immutable `SchemaSet` descriptors (`Packman\Schema\Data\Migration\SchemaSet`):

```php
new SchemaSet(
    package: 'axiomasoft/packman-modules',
    name: 'tenant',
    scope: SchemaScope::Tenant,          // Central | Tenant | Dedicated
    path: __DIR__.'/../../database/migrations/tenant',
    version: 1,
    drivers: ['pgsql', 'sqlite'],        // 'pgsql' is mandatory in $drivers
    lifecycle: SchemaLifecycle::Module,  // Platform | Module
    requires: [],
    connection: null,                    // Required iff scope === SchemaScope::Dedicated
);
```

### Key Validation Rules (`SchemaSet` & `TaggedSchemaSets` & `DefaultSchemaMigrator`)

- **Scope vs. Connection:** `SchemaScope::Dedicated` requires a non-null `$connection`; `Central` and `Tenant` forbid specifying `$connection` (and `SchemaMigrator` rejects connection overrides on `Dedicated` sets).
- **Driver Preflight:** `'pgsql'` must appear in `$drivers`; `DefaultSchemaMigrator` verifies every selected set supports the target connection driver before creating or mutating the `migrations` journal.
- **Lifecycle Separation:** `SchemaLifecycle::Module` sets are skipped unless `--modules` (`includeModules: true`) is passed to `packman:schema:migrate` or `SchemaMigrator::migrate()`.
- **Conditional Sets:** Sets listing `$requires` activate only when every required package also contributes sets.
- **Duplicate Guards:** `TaggedSchemaSets` rejects duplicate `<package>:<name>` IDs (`CoreErrorCode::SchemaDuplicateSet`) and duplicate migration filenames within the same scope (`CoreErrorCode::SchemaDuplicateMigration`).

---

## 2. Noun Ownership & Physical Table Prefixing

### `SchemaOwnershipRegistry`

`Packman\Schema\Internal\Compiler\SchemaOwnershipRegistry` (bound to `SchemaOwnership`) tracks package-owned logical nouns (`declare(string $owner, array $nouns)`):

- Rejects non-snake-case nouns and duplicate ownership across different owners (`InvalidConfiguration`).
- `ownerOf(string $logical)` resolves exact nouns, pluralized forms (`Str::plural($noun)`), or `<noun>_*` prefixes, using **longest-matching noun precedence** (for example `billing_invoice` overrides `billing`).
- Packages implementing `WithSchema::tableNouns()` automatically register their nouns through `ExtensionRegistrar` (`packman-plugins`) or `ModulesSchemaSets` / `ModuleMigrationBoundary`.

### `TableNames` & `TablePrefix`

- `Packman\Config\Data\TablePrefix` validates prefixes against `/\A[a-z][a-z0-9]{1,6}_\z/`.
- `Packman\Schema\Data\Compiler\TableNames` resolves logical table names to physical names using the configured prefix (`packman.schema.table_prefix`, default `pmn_`) and connection (`packman.schema.connection`).

---

## 3. PostgreSQL 63-Byte Identifiers & `PackmanMigration`

PostgreSQL truncates identifiers at 63 bytes (`NAMEDATALEN - 1`). `Packman\Schema\Data\Compiler\ConstraintNames` deterministically builds and shortens every index and constraint name:

- Primary key: `{table}_pk` (`ConstraintNames::primary($table)`)
- Index: `{table}_{cols}_ix` (`ConstraintNames::index($table, $columns)`)
- Unique: `{table}_{cols}_uq` (`ConstraintNames::unique($table, $columns)`)
- Foreign key: `{table}_{cols}_fk` (`ConstraintNames::foreign($table, $columns)`)
- Check: `{table}_{name}_ck` (`ConstraintNames::check($table, $name)`)
- Exclusion (`EXCLUDE USING gist`): `{table}_{name}_ex` (`ConstraintNames::exclusion($table, $name)`)
- Names exceeding 63 bytes are shortened deterministically by `ConstraintNames::shorten()`:

```text
substr($name, 0, 54) . '_' . substr(sha1($name), 0, 8)
```

### `Packman\Schema\Laravel\PackmanMigration`

All package migrations should extend `PackmanMigration`, which provides:

- `create(string $logical, Closure $define)` — resolves physical table name via `TableNames` and renames PostgreSQL's default `{table}_pkey` constraint to `{table}_pk`.
- `drop(string $logical)` — drops the physical table.
- Column & constraint helpers: `uuidPrimary()`, `identityPrimary()`, `money()`, `ref()` (`varchar(191)` cross-package reference without foreign keys), `timestampsTz()` (`timestamptz(6)`), `index()`, `unique()`, `foreign()`, `check()`, and `exclude()`.

---

## 4. Pure Schema Step Compiler & Governance Gates

### `SchemaCompiler` (`Packman\Schema\Internal\Compiler\SchemaCompiler`)

Provides pure compile-time validation of `SchemaComponent` / `SchemaStep` / `RetiresSteps` graphs against `SchemaSpace` definitions:

- Enforces monotonic `YYYY_MM_DD_HHMMSS_<snake_name>` step IDs per component and across dependencies (dependent first steps must strictly follow dependency first steps).
- Runs Kahn's topological sort to reject dependency cycles.
- Computes physical identifier lengths (`<= 63` bytes) and checks `StorageCollisionDetector` across connection identities.
- `SchemaFingerprint` computes deterministic prefix + version hashes for schema state verification.

### Automated Governance Gates

- **`TableOwnershipGate`:** Scans `database/migrations` across all packages in `packman.json` to ensure every `Schema::create('table', ...)` targets a valid identifier owned by at most one package.
- **`TableWriteOwnershipGate`:** Blocks direct `DB::table(...)->insert/update/upsert/delete/truncate` writes against `migrations`, `sys_*`, and `mod_*` outside `axiomasoft/packman-schema` and `axiomasoft/packman-modules`.
- **`SchemaGate`:** Enforces `YYYY_MM_DD_NNNNNN_name.php` naming and unique monotonic timestamp prefixes per package.
- **Module PHPStan Boundary Rules (`packages/modules/extension.neon`):** `NoForeignPrefixSqlRule`, `NoDirectSystemTableWriteRule`, and pooling safety rules (`NoSetWithoutLocalRule`, `NoSessionAdvisoryLockRule`, `NoListenNotifyRule`, `NoTempTableRule`, `NoSqlPrepareRule`, `NoEvalRule`).
