---
name: packman-config-errors
description: Implement Packman OptionsFactory classes, ConfigSection schemas, SecretRef handling, ErrorCode enums, ProblemDefinitionProviders, and DoctorChecks.
---

# Packman Configuration, Errors & Diagnostics

## Configuration (`Packman\Config`)

1. Implement `OptionsFactory::fromArray(array $config, string $path): self` in an immutable `final readonly class`, accumulating errors with `ConfigurationValidator` or `JournalingConfigReader` + `SectionCompiler`.
2. Claim root config keys in `register()` using `ConfigSections::own()` and register `ConfigReportSectionInterface` for `packman:config:validate`.
3. Never call `config()`, `env()`, `Config::get()`, or read PHP superglobals inside domain code (`ConfigCompositionGate`, `RuntimeGlobalsGate`).
4. Wrap sensitive values in `SecretRef` and validate prefixes with `TablePrefix`.
5. Sync JSON config schemas: `./bin/php84 bin/packman config-schema --write`.

## Errors & Diagnostics (`Packman\Errors`, `Packman\Doctor`)

1. Create a backed string enum implementing `Packman\Errors\Contracts\ErrorCode` with `<prefix>.<snake_case>` wire codes.
2. Register every case in a `ProblemDefinitionProvider` using the `urn:packman:problem:` URN prefix and matching `ErrorCategory` / HTTP status.
3. Throw `CodedException` subclasses returning `ErrorDescriptor` with RFC 6901 `path` and bounded scalar/flat details (`<= 4096` bytes).
4. Sync error catalogs: `./bin/php84 bin/packman error-codes --write`.
5. Register `DoctorCheck` / `HealthCheck` implementations on `packman.doctor_checks` / `packman.health_checks` tags and verify via `packman:doctor --json`.
