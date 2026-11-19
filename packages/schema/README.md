# Packman Schema (`axiomasoft/packman-schema`)

Schema governance, scoped `SchemaSet` migration planning, logical table noun ownership (`SchemaOwnership` / `TableNames`), prefix fingerprinting (`SchemaFingerprint`), PostgreSQL 63-byte constraint naming (`ConstraintNames` / `PackmanMigration`), and compile-time `SchemaCompiler` for the Packman ecosystem.

## Ordering package migrations

A schema set can declare `prerequisiteSets: ['acme/billing:platform']`. Both scope migrations and explicit `migrateSets()` calls include transitive prerequisites on the same connection. The migrator applies each prerequisite before its dependents, including when migration filenames would sort in a different order. Platform sets can depend on other platform sets. Module sets can also depend on other modules.

Use `requires` for optional integration with a package that contributes schema sets. Use `prerequisiteSets` for a migration dependency on a particular set.
