# axiomasoft/packman-modules

Composer-discovered DB-backed platform module and plugin compiler, lifecycle state machine (`available -> enabled <-> disabled -> archived -> purged`), automatic PostgreSQL DDL ownership claims (`mod_records`), epoch-invalidated registry caching, and PHPStan static isolation rules.

## Discovery & Configuration

By default, `ModuleDiscovery` scans `vendor/composer/installed.json` for packages with types `packman-module`, `packman-plugin`, `module`, or `plugin`, resolving `ModuleDefinition` subclasses from `extra.packman.module`, `extra.packman.plugin`, `extra.module`, or `extra.plugin`. All package types, extra keys, table names, cache path, and lock keys are configurable through `ModulesOptions` / `config/modules.php`.

Modules may also compose kernel `Packman\Extensions\Contracts\With*` contracts and traits (`WithConfiguration`, `WithDatabaseSettings`, `WithSchema`, `WithFeatureFlags`, `WithLifecycleHooks`), automatically hydrated into `ModuleManifest` via `ExtensionInspector`.
