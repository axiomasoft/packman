# Packman Schema (`axiomasoft/packman-schema`)

Schema governance, scoped `SchemaSet` migration planning, logical table noun ownership (`SchemaOwnership` / `TableNames`), prefix fingerprinting (`SchemaFingerprint`), PostgreSQL 63-byte constraint naming (`ConstraintNames` / `PackmanMigration`), and compile-time `SchemaCompiler` for the Packman ecosystem.

## Ordering package migrations

A schema set can declare `prerequisiteSets: ['acme/billing:platform']`. Both scope migrations and explicit `migrateSets()` calls include transitive prerequisites on the same connection. The migrator applies each prerequisite before its dependents, including when migration filenames would sort in a different order. Platform sets can depend on other platform sets. Module sets can also depend on other modules.

Use `requires` for optional integration with a package that contributes schema sets. Use `prerequisiteSets` for a migration dependency on a particular set.

## Sharing a database

Declare the extension’s schema prefix with its owned table nouns. Two packages can each own `channels` under different prefixes. Resolve a specific family through `SchemaExtensions::ownershipFor($ownership, $prefix)`. Unqualified lookup succeeds when the owner is unambiguous. Packages with their own installations can pass their Composer name as `owner` to `TableNames` and `SchemaFingerprint::document()`. The optional `ownsLegacy` callback identifies that package’s older unlabelled installations before a prefix change.

Invoke `SchemaMigrator::migrate()` and `migrateSets()` in their owning Laravel application. Migration files can use Laravel facades, so the application container, facade application and database manager must agree before execution.
