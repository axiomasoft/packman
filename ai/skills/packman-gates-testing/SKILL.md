---
name: packman-gates-testing
description: Run Packman's 16 quality gates, PHPStan level max, Pint, Rector, SQLite/PostgreSQL test suites, and subtree/contract verifications.
---

# Packman Quality Gates & Testing Verification

## Mandatory Verification Sequence

Execute through `./bin/php84` after code or contract edits:

```bash
./bin/php84 bin/packman php-api --write
./bin/php84 bin/packman error-codes --write
./bin/php84 bin/packman config-schema --write
./bin/php84 bin/packman ai:sync
./bin/php84 composer refactor:check
./bin/php84 composer lint:check
./bin/php84 composer analyse
./bin/php84 composer gate
./bin/php84 composer php-api
./bin/php84 composer error-codes
./bin/php84 composer config-schema
./bin/php84 composer ai:sync
./bin/php84 composer test
./bin/php84 composer test:pg
```

## Rules for Tests & Fixtures

- Extend `Packman\Testkit\PackageTestCase` for Laravel package suites and reuse `PluginContractTests` / `ModuleContractTests` / `AssertsRegisteredErrors`.
- Keep PostgreSQL tests guarded by `TestDatabaseGuard` (`PGSQL_DATABASE` ending in `_test` and `PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1`).
- Ensure `QualityCeilingGuard` stays clean: zero `eval()`, `dd()`, `dump()`, `var_dump()`, `extract()`, `compact()`, or inline `@phpstan-ignore` in production `src/`.
- Verify zero downstream product/platform identifier leaks across `packages/`, `schemas/`, `ai/`, `bin/`, `tests/`, `fixtures/`, `docs/architecture/`, and `docs/reference/`.
