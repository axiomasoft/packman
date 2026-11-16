---
name: packman-architecture
description: Enforce Packman 4-layer DAG, php-library purity, surface attributes (#[Api]/#[Spi]/#[Internal]), and thin PackageRegistrar wiring.
---

# Packman Architecture & Surface Boundaries

## Workflow

1. **Check Package Profile & Layer** in `packman.json` and `schemas/profiles/*.profile.json`:
   - `L1` (`php-library`): `axiomasoft/packman` (`packages/core`) — zero `laravel/*` or `illuminate/*` in `composer.json` `require`.
   - `L2` (`laravel-package` / `modular-platform`): `packman-schema`, `packman-plugins`, `packman-modules`, `packman-panels`.
   - `L3` (`laravel-package`): `packman-filament`.
   - `L4` (`dev_only: true`): `packman-testkit`, `packman-devtools` — never imported by runtime packages.
2. **Enforce Surface Attributes** (`Packman\Attributes`):
   - Tag every class, interface, trait, and enum in `src/` with `#[Api(since: '1.0')]`, `#[Spi(kind: SpiKind::Implement|SpiKind::Call, since: '1.0')]`, or `#[Internal]`.
   - Never leak `#[Internal]` types in public/SPI method parameters, return types, or properties.
   - Never import another package's `\Internal\` namespace (`OwnershipGate`).
3. **Keep Service Providers Slim** (`<= 80` lines):
   - Use `RegistersPackageRegistrars` and `PackageRegistrar` subclasses (`RegistersNothingOnBoot` when boot is empty).
   - Guard optional integrations (`ModuleRegistry`, `SchemaSetProvider`, `PluginInspector`) with `interface_exists()` and `$app->bound()`.
4. **Verify**:
   - `./bin/php84 bin/packman php-api --write && ./bin/php84 composer php-api`
   - `./bin/php84 bin/packman gate --only=package-graph,module-layout,ownership,attributes-architecture,provider-size`
