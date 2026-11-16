# Project Migration Spec 03: `chatom`

**Repository Path:** `/home/vostrikov/projects/packages/chatom`  
**Target Profile:** `laravel-package` (7 subpackages: `core`, `attachments`, `forum`, `poll`, `voice-message`, `webhooks`, `testkit`)  
**Zero Host-Product Coupling:** Standalone modular communication/chat platform consuming `axiomasoft/packman` + `packman-{plugins,schema,testkit,devtools}`.

---

## 1. Best Practices Extracted from `chatom` into `packman`

1. **`Packman\Plugins` (from `packages/core/src/Plugins/*`, `PollPlugin`, `WebhooksPlugin`, `BroadcastingPlugin`, `SearchPlugin`):**
   - Strict compile-time code extensions with **zero database activation records** (`$disabled` config list only).
   - Capability-based decoupling (`PluginManifest` with `id`, `resourceRoot`, `databases`, never naming sibling plugins directly).
   - Pure declarative `PluginRegistrar` (`configDefinitions`, `schemaDefinition`, `stage`, `healthCheckDefinition`, `capability`, `driver`, `spaceEntity`, `routeGroup`, `commandDefinitions`, `rebuilderDefinition`, `prunerDefinition`, `purgeMemberDefinition`, `outboxConsumerDefinition`).
   - `PluginGraphCompiler` partitioned into specialized sub-compilers + `ContributionChecks` single-pass error accumulation + DB engine compatibility assertions (`assertDatabases()`, `assertNodeSchemas()`).
2. **`Packman\Config` (in `axiomasoft/packman`, from `packages/core/src/Configuration/*`):**
   - `ConfigSection` + `JournalingConfigReader` (type-safe coercion recording all errors in `CompileJournal` under full dotted keys, secret masking `/(secret|password|token|key)$/i`, `unsafeBool` production guard requiring explicit opt-in).
   - `SectionCompiler` closed-schema verification with **Levenshtein typo suggestions** (`did you mean "<candidate>"?`).
   - `SectionRegistrar` container factory resolution enabling instant test resets without rebooting Laravel.
   - `DefinitionsCache`: SHA-256 fingerprint over `FORMAT`, `InstalledVersions`, plugin reflection file hashes, raw config roots, section list, `CompileContext`, and codec output; executes **round-trip codec check** before atomic write (`tempnam` + `chmod` + `rename` + `opcache_invalidate`).
3. **`Packman\Schema` (from `packages/core/src/Schema/*`):**
   - `SchemaComponent` (`id`, `tables`, `steps`, `dependsOn`, `appliesTo`) & pure `SchemaStep` (`up`/`down` reading only passed `SpaceSchema`, zero `config()`/container calls).
   - `SchemaCompiler` verifying dependency graph + cycle detection + strict cross-component timestamp ordering.
4. **`Packman\Doctor` (in `axiomasoft/packman`, from `packages/core/src/Diagnostics/*`):**
   - `HealthCheck` SPI (`code()`, `slice()`), `DoctorFinding` (`code`, `severity`, `slice`, `message`, `remediation`), and cascade handling (`skipped_connection_failed` when DB probe fails).
5. **`Packman\Testkit` (from `packages/testkit/*`):**
   - `migrateOncePerProcess` fingerprint optimization for RefreshDatabase.
   - `TestDatabaseGuard`: fail-closed defense rejecting system schemas, verifying `127.0.0.1`/`localhost`/`::1`, querying `SELECT current_database()` for `*_test`, requiring destructive opt-in.
6. **`Packman\DevTools` (from `scripts/*`, `split.json`, `tests/Arch/*`):**
   - `OwnersCatalogue` (`extra.packman.owns`), L1–L4 cross-package & internal gates, provider `register()` no-I/O gate, no-DB-in-API-Resource gate, gate mutation test fixtures, and JSON Schema / OpenAPI / error-code generators + `split.json`.

---

## 2. File-by-File Migration Spec Across All 7 Subpackages

### 2.1. `packages/core` (`axiomasoft/chatom`)
- **`composer.json`**: Require `axiomasoft/packman`, `packman-plugins`, `packman-schema`; declare `extra.packman.owns` (keeping dual `extra.chatom.owns` during transition).
- **Attributes (`src/Attributes/{Api,Spi,SpiKind}.php`)**: Replace/alias with `Packman\Attributes\*`.
- **Errors (`src/Errors/`)**:
  - `Enums/ErrorCategory.php` -> `Packman\Errors\Enums\ErrorCategory`
  - `Enums/ErrorCode.php` -> implements `Packman\Errors\Contracts\ErrorCode`
  - `Exceptions/ChatomException.php` -> extends `Packman\Errors\Exceptions\CodedException`
- **Configuration (`src/Configuration/`)**:
  - `Contracts/{ConfigSection,ConfigReader,SettingsLookup}.php` -> `Packman\Config\Contracts\*`
  - `Data/CompileContext.php` -> extends/aliases `Packman\Config\Data\CompileContext`
  - `Exceptions/InvalidConfigurationException.php` -> `Packman\Config\Exceptions\InvalidConfiguration`
  - `Internal/Services/{CompileJournal,JournalingConfigReader,SectionCompiler,CompiledSections,DefinitionsCache,SectionRegistrar}.php` -> delegate to / consume `Packman\Config\*`
  - `Internal/Services/DefinitionsCodec.php`, `ConfigurationCompiler.php`, `DefinitionsLoader.php` -> rebase on `Packman\Config` while retaining Chatom stage-2 `RuntimeDefinitions` (space/pipeline compilation)
  - Update all 22 sections under `src/Configuration/Internal/Sections/*` to import `Packman\Config\Contracts\{ConfigSection,ConfigReader}`.
- **Plugins (`src/Plugins/`)**:
  - `Contracts/{Plugin,GraphView,DriverFactory}.php` -> `Packman\Plugins\Contracts\*`
  - `PluginManifest.php` -> `Packman\Plugins\Data\PluginManifest`
  - `Exceptions/{InvalidPluginException,InvalidPluginGraphException}.php` -> `Packman\Plugins\Exceptions\*`
  - `Internal/PluginGraphCompiler.php`, `CompiledPluginGraph.php`, `PluginRegistrar.php`, `Plugins.php` -> delegate core graph compilation/sorting to `Packman\Plugins` while retaining Chatom-specific node/channel/space sub-compilers.
  - Align internal plugins `BroadcastingPlugin`, `SearchPlugin`, `PresencePlugin` to `Packman\Plugins\Contracts\ExtensionPlugin`.
- **Schema (`src/Schema/`)**:
  - `Contracts/{SchemaComponent,SchemaStep,RetiresSteps}.php` & `Internal/Services/SchemaCompiler.php` -> implement/delegate to `Packman\Schema\*`.
- **Diagnostics (`src/Diagnostics/`)**:
  - `Contracts/{HealthCheck,ConnectionIndependentHealthCheck}.php`, `Enums/Severity.php`, `Data/{DoctorFinding,DoctorReport,HealthCheckDefinition}.php`, `DoctorReportBuilder.php`, `DoctorCommand.php` -> rebase on `Packman\Doctor\*`.

### 2.2. Companion Subpackages (`attachments`, `forum`, `poll`, `voice-message`, `webhooks`)
All 5 companion plugins follow the exact unified pattern:
- **`packages/attachments`**: `AttachmentsPlugin` (`Packman\Plugins`), `AttachmentsServiceProvider` (`Plugins::add`), `AttachmentsErrorCode` (`Packman\Errors`), `AttachmentsSection` (`Packman\Config`), `AttachmentsSchemaComponent` (`Packman\Schema`), `AttachmentsSignedUrlCheck` (`Packman\Doctor\Contracts\HealthCheck`).
- **`packages/forum`**: `ForumPlugin`, `ForumServiceProvider`, `ForumErrorCode`, `ForumFoundationContribution`, 4 `Sections/*`, `ForumSchemaComponent`, `ForumContextHealthCheck` -> aligned to `Packman\*`.
- **`packages/poll`**: `PollPlugin`, `PollServiceProvider`, `PollSchemaComponent`, `StoredSourcesHealthCheck` (`DOC-STORED`) -> aligned to `Packman\*`.
- **`packages/voice-message`**: `VoiceMessagePlugin`, `VoiceMessageServiceProvider`, `VoiceMessageErrorCode`, `VoiceProbeSection`, `VoiceWaveformSection`, `FfprobeHealthCheck` -> aligned to `Packman\*`.
- **`packages/webhooks`**: `WebhooksPlugin`, `WebhooksServiceProvider`, refactor `UnknownWebhookEndpointException` from `RuntimeException` to extend `ChatomException` (`CodedException` with `webhooks.unknown_endpoint` and `ErrorCategory::NotFound`), 3 `Sections/*`, `WebhooksSchemaComponent`, 3 Doctor slices (`WebhookBacklogDoctorSlice`, `WebhookWorkerDoctorSlice`, `EgressProfileDoctorSlice`) -> aligned to `Packman\*`.

### 2.3. `packages/testkit` & Root Tooling
- **`packages/testkit`**: Require `axiomasoft/packman-testkit`; `PackageTestCase` and `PluginContractTestCase` extend `Packman\Testkit\*`; replace `TestDatabaseGuard` & `UnsafeTestDatabaseException` with `Packman\Testkit\*` while preserving Chatom's `Space` helpers.
- **Root**: Add `packman.json` (`profile: "laravel-package"`); migrate `scripts/architecture-conformance.php`, `generate-error-codes.php`, `generate-config-schema.php`, `build-contracts.php`, `tests/Arch/Support/{OwnersCatalogue,ArchitectureGateScanner}.php`, and `split.json` execution onto `axiomasoft/packman-devtools` (`bin/packman`). Run `packman ai:sync`.
