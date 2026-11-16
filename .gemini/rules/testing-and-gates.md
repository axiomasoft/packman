# Testing & Quality Gates

- Run all workspace commands through `./bin/php84` so PHP 8.4 and required extensions are active.
- Keep SQLite (`./bin/php84 composer test`) and PostgreSQL (`./bin/php84 composer test:pg`) suites passing. `TestDatabaseGuard` rejects any PostgreSQL database name not ending in `_test` and requires `PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1` for destructive resets.
- Use `Packman\Testkit\PackageTestCase` and conformance traits (`PluginContractTests`, `ModuleContractTests`, `AssertsRegisteredErrors`, `IsolatesDatabasePath`, `UsesSuiteDatabase`, `Concurrency`) when writing package tests.
- Never suppress static analysis with inline `@phpstan-ignore` in non-dev `src/`, never leave `dd()`, `dump()`, `var_dump()`, `extract()`, `compact()`, or `eval()` in `src/` (`QualityCeilingGuard`), and verify:
  - `./bin/php84 composer lint:check`
  - `./bin/php84 composer analyse`
  - `./bin/php84 composer refactor:check`
  - `./bin/php84 composer gate`
