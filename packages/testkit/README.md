# axiomasoft/packman-testkit

Shared **dev-only** testing harnesses, PostgreSQL database guards, multi-process concurrency barriers, contract schema verifiers, and fake recorders for Packman ecosystem packages.

> **Never depend on `axiomasoft/packman-testkit` in production `require`.** Add it to `require-dev` only.

## Capabilities

- **Test Database Guard (`Packman\Testkit\Support\Database\TestDatabaseGuard`)**: Unconditionally requires `*_test` / `*_testing` database names (or `:memory:` for SQLite) regardless of `PACKMAN_TEST_DESTRUCTIVE_OPT_IN`, and requires `PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1` for destructive schema resets and non-local test hosts. Supports `PACKMAN_TEST_DB_PER_PROCESS` worker lane isolation (`PGSQL_*` and `DB_*` env variables).
- **Concurrency Harnesses (`Packman\Testkit\Concurrency\Process\Concurrency`, `ForkRace`, `PostgresBarrier`)**: Synchronized `pcntl_fork` races and PostgreSQL advisory/table barriers for testing transactional invariants.
- **Conformance Traits (`PluginContractTests`, `ModuleContractTests`, `AssertsRegisteredErrors`)**: Reusable contract verification suites for `ExtensionPlugin`, `ModuleDefinition`, and `ProblemRegistry` registrations.
- **OpenAPI & JSON Schema Contracts (`ContractSchema`, `OpenApiResponse`, `ProblemProbeRoutes`)**: Payload and RFC 7807 / schema validation via `opis/json-schema`.
- **Fake Recorders & Fixtures (`FakeMetricsRecorder`, `FakeTraceRecorder`, `MutableTenantResolver`, `CanonicalJson`, `FailingStream`, `Scale`)**: Deterministic in-memory doubles and stream/JSON helpers.
