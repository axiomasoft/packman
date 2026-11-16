# Project Migration Spec 02: `commerz`

**Repository Path:** `/home/vostrikov/projects/packages/commerz`  
**Target Profile:** `laravel-package` (9 subpackages: `core`, `catalog`, `inventory`, `pricing`, `promotions`, `sales`, `shipping`, `full`, `testkit`)  
**Zero `corex` Coupling:** Pure standalone modular e-commerce monorepo consuming `axiomasoft/packman` + `packman-{plugins,schema,panels,testkit,devtools}`.

---

## 1. Best Practices Extracted from `commerz` into `packman`

1. **`Packman\Plugins` (from `packages/core/src/Plugins/*` & `PluginGraph.php`):**
   - `ExtensionPlugin` (`manifest(): PluginManifest`, `register(PluginRegistrar $registrar): void`; zero constructor arguments, zero I/O, zero DB).
   - `PluginManifest` (`vendor.name` regex, SemVer validation, strict `requires` vs `optional` disjointness, no self-reference).
   - `PluginGraphCompiler` using **Kahn's topological sort** with deterministic `SORT_STRING` tie-breaking, cycle participant isolation, transitive ancestor ownership enforcement (`ContributionKind`/`SlotDefinition` owner must be self or required ancestor), and container resolution validation.
   - `FrozenContributions` (`Contributions` & `PluginInspector` — immutable, Octane-safe).
   - `PluginRegistrar::seal()` guarding against post-registration mutations (`LogicException`).
2. **`Packman\Config` (from `CommerzConfig` & `*Config`):**
   - Fail-closed `final readonly *Options::fromArray($config, $path)` with dotted path errors (`commerz-pricing.tax.calculator`) and zero silent defaults.
3. **`Packman\Schema` (from `packages/core/src/Schema/*`):**
   - `SchemaOwnershipRegistry` (longest-matching noun ownership + duplicate noun collision check).
   - `ConstraintNames::shorten()` (63-byte PostgreSQL `NAMEDATALEN - 1` deterministic hash truncation: `substr($name, 0, 54) . '_' . substr(sha1($name), 0, 8)`).
   - `PackmanMigration` (auto `{table}_pk`, `uuidPrimary()`, `timestampsTz()` UTC `timestamptz(6)`, `money()`, uncoupled `ref()` `varchar(191)`).
   - `SchemaFingerprint` (persisted SHA-1 of prefix + ID strategy + version).
4. **`Packman\Doctor` (from `DoctorCommand.php`):**
   - Unified probe runner (`Configuration`, `Database & fingerprint`, `Plugins`).
5. **`Packman\Testkit` (from `packages/testkit/*`):**
   - `Concurrency::run($workers, $work, $timeout)` (`pcntl_fork` + barrier files `ready.*`/`go` for parallel PostgreSQL lock testing).
   - Multi-provider migration state reset in `PackageTestCase` + `PluginContractTests` trait.
6. **`Packman\DevTools` (from `tests/Arch/*`):**
   - 6 AST gates (`dependencies`, `internals`, `markers`, `eloquent`, `tables`, `floats`) with positive/negative test fixtures + `ApiManifest` (`php-api.json` supporting PHP 8.4 asymmetric visibility).

---

## 2. Shared Primitives Replacement Matrix in `commerz`

| Current `commerz` Class | Target `packman` Replacement |
|:--|:--|
| `Commerz\Attributes\{Api,Spi,SpiKind}` | `Packman\Attributes\{Api,Spi,SpiKind}` (`axiomasoft/packman`) |
| `Commerz\Errors\ErrorCategory` | `Packman\Errors\Enums\ErrorCategory` (`axiomasoft/packman`) |
| `Commerz\Errors\CommerzException` | Extends `Packman\Errors\Exceptions\CodedException` (`axiomasoft/packman`) |
| `Commerz\Errors\InvalidConfiguration` | `Packman\Config\Exceptions\InvalidConfiguration` (`axiomasoft/packman`) |
| `Commerz\Plugins\{PluginManifest,PluginRegistrar,ContributionKind,ContributionDescription,PluginDescription,PluginSource}` | `Packman\Plugins\{Data/PluginManifest,Laravel/PluginRegistrar,Data/CapabilityDefinition,Data/ContributionDescription,Data/PluginDescription,Enums/PluginSource}` |
| `Commerz\Plugins\Contracts\{CommerzPlugin,Contributions,PluginInspector}` | `Packman\Plugins\Contracts\{ExtensionPlugin,Contributions,PluginInspector}` |
| `Commerz\Plugins\Internal\{PluginGraphCompiler,FrozenContributions,PluginCatalog,PluginDeclarations,PluginNode,PluginEntry}` | `Packman\Plugins\Internal\*` |
| `Commerz\Plugins\Internal\Console\PluginsCommand` | `Packman\Plugins\Laravel\Console\ExtensionsCommand` |
| `Commerz\PluginGraph` | `Packman\Plugins\Testing\PluginGraph` |
| `Commerz\Schema\Contracts\SchemaOwnership` / `Internal\SchemaOwnershipRegistry` | `Packman\Schema\Contracts\SchemaOwnership` / `Internal\SchemaOwnershipRegistry` |
| `Commerz\Schema\ConstraintNames` | `Packman\Schema\Data\ConstraintNames` |
| `Commerz\Schema\CommerzMigration` | Extends `Packman\Schema\Laravel\PackmanMigration` |
| `Commerz\Internal\Console\DoctorCommand` | `Packman\Doctor\Laravel\Console\DoctorCommand` (`axiomasoft/packman`) |
| `Commerz\Testkit\{PackageTestCase,PluginContractTests,Concurrency}` | `Packman\Testkit\{PackageTestCase,Conformance/PluginContractTests,Concurrency}` |

---

## 3. File-by-File Migration Spec Across All 9 Subpackages

1. **`packages/core` (`axiomasoft/commerz`):**
   - `composer.json`: Require `axiomasoft/packman`, `packman-plugins`, `packman-schema`, `packman-panels` + `extra.packman`.
   - Delete replaced duplicates: `src/Attributes/*`, `src/Errors/{InvalidConfiguration,ErrorCategory}.php`, `src/Plugins/{PluginManifest,ContributionKind,ContributionDescription,PluginDescription,PluginSource}.php`, `src/Plugins/Internal/{PluginGraphCompiler,FrozenContributions,PluginCatalog,PluginDeclarations,PluginNode,PluginEntry,Console/PluginsCommand}.php`, `src/Schema/{Contracts/SchemaOwnership,Internal/SchemaOwnershipRegistry}.php`.
   - Refactor `src/Configuration/CommerzConfig.php` to implement `Packman\Config\Contracts\OptionsFactory`.
   - Refactor `src/Internal/CorePlugin.php` to implement `Packman\Plugins\Contracts\ExtensionPlugin` (`outbox_consumer`, `sellable_provider`, `quote_stage`).
   - Update `src/CommerzServiceProvider.php` to register `SchemaSet` (nouns: `store`, `channel`, `schema`, `operation`, `outbox`), `DoctorCheck`s, and headless `StorePanel` / `ChannelPanel`.
2. **`packages/catalog` (`axiomasoft/commerz-catalog`):**
   - `CatalogConfig` -> implements `Packman\Config\Contracts\OptionsFactory`.
   - `CatalogPlugin` -> implements `ExtensionPlugin` (declares `attribute_type`, `product_kind`; contributes 10 attribute types, 3 product kinds, `VariantSellables`).
   - Add headless `src/Panels/CatalogPanelProvider.php` (`Packman\Panels\Contracts\PanelProvider`) declaring panels for `products`, `variants`, `categories`, `attributes` without Filament dependency.
   - `CatalogServiceProvider` declares `SchemaSet` (nouns: `product`, `variant`, `option`, `attribute`, `category`, `listing`).
3. **`packages/inventory` (`axiomasoft/commerz-inventory`):**
   - `InventoryConfig` (`OptionsFactory`), `InventoryPlugin` (`ExtensionPlugin`: `allocation_strategy`), `InventoryServiceProvider` (`SchemaSet` noun `stock`), plus new headless `src/Panels/InventoryPanelProvider.php` (`stock_locations`, `stock_levels`).
4. **`packages/pricing` (`axiomasoft/commerz-pricing`):**
   - `PricingConfig` (`OptionsFactory`), `PricingPlugin` (`ExtensionPlugin`: `tax_calculator`, `TaxStage`), `PricingServiceProvider` (`SchemaSet` nouns `price`, `tax`), plus new headless `src/Panels/PricingPanelProvider.php` (`price_lists`, `tax_zones`, `tax_rates`).
5. **`packages/promotions` (`axiomasoft/commerz-promotions`):**
   - `PromotionsConfig` (`OptionsFactory`), `PromotionsPlugin` (`ExtensionPlugin`: `promotion_condition`, `promotion_effect`, `promotion_activator`, 3 quote stages), `PromotionsServiceProvider` (`SchemaSet` nouns `promotion`, `coupon`), plus new headless `src/Panels/PromotionsPanelProvider.php` (`promotions`, `coupons`).
6. **`packages/sales` (`axiomasoft/commerz-sales`):**
   - `SalesConfig` (`OptionsFactory`), `SalesPlugin` (`ExtensionPlugin`: `payment_gateway`, `order_number_generator`), `SalesServiceProvider` (`SchemaSet` nouns `cart`, `checkout`, `order`, `payment`, `fulfillment`, `return`, `refund`), plus new headless `src/Panels/SalesPanelProvider.php` (`orders`, `payments`, `fulfillments`, `returns`).
7. **`packages/shipping` (`axiomasoft/commerz-shipping`):**
   - `ShippingConfig` (`OptionsFactory`), `ShippingPlugin` (`ExtensionPlugin`: `delivery_rate`, `carrier`, `DeliveryStage`), `ShippingServiceProvider` (`SchemaSet` noun `shipping`), plus new headless `src/Panels/ShippingPanelProvider.php` (`shipping_zones`, `shipping_methods`, `carriers`).
8. **`packages/full` (`axiomasoft/commerz-full`):**
   - Update dependency constraints in metapackage `composer.json`.
9. **`packages/testkit` (`axiomasoft/commerz-testkit`):**
   - Require `axiomasoft/packman-testkit`; `PackageTestCase`, `PluginContractTests`, `Concurrency` inherit/alias `Packman\Testkit\*`; keep `Commerce::fake()` port mocking untouched.
10. **Root `commerz` Gates & Manifests:**
    - Add root `packman.json` (`profile: "laravel-package"`).
    - Replace bespoke `tests/Arch/Support/{ArchitectureGates,SourceScanner}.php` and `scripts/generate-api-manifest.php` with `packman gate` & `packman php-api` via `axiomasoft/packman-devtools` (preserving `floats` ban in `Contracts/`/`Data/` via custom gate rule).
    - Run `packman ai:sync` and verify PHPStan max, Pint, Rector, SQLite, and PG concurrency tests.
