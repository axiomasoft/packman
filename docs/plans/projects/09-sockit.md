# Project Migration Spec 09: `sockit`

**Repository Path:** `/home/vostrikov/projects/packages/sockit`  
**Target Profile:** `laravel-package` (Fresh Scaffold from Existing Specifications via `bin/packman`)  
**Zero Host-Product Coupling:** Built from commit 1 on `axiomasoft/packman` + `packman-{plugins,panels,schema,testkit,devtools}` following `audits/sockit-reports-restored/*` and `plans/2026.10.17-№1-SOCKIT-FOUNDATION/*`.

---

## 1. Critical Architectural Boundaries Verified From `sockit` Specs

1. **Authoritative Writer & Atomic SQL Profile (`01-sockit-architecture-v1.md`):**
   - Single writer per state identity; feeds/caches/search projections never write state.
   - Domain state + monotonic CAS revision + idempotency receipt + transactional outbox committed in one atomic SQL transaction.
   - Read-path re-authorization: every read re-evaluates current `Audience` & `PrivacyFloor`.
2. **M6 / D14 Tombstone (`knowledge/comments-module.json`):**
   - **Comments are permanently excluded from `sockit`** (delegated strictly to `Chatom Comments/Forum`).
   - Do **NOT** create `packages/comments` or `comments.*` operations. `sockit` owns **Surfaces, Reactions, Native Posts, Feeds, Groups, and Audience**.
3. **Headless Storage-Boundary Panels (`knowledge/panel-design.json`):**
   - A Panel is a server-side isolation boundary (`PanelId`, `hostTypes`, `connection`, `schema`, `prefix`, `tables`, `models`, enabled `modules`, `PrivacyFloor`), ensuring multi-panel hosts (e.g., `edufy.community` vs `edufy.support`) never collide on cache keys, cursors, or outbox records.

---

## 2. Target Monorepo Structure Scaffolded on `packman`

```text
/home/vostrikov/projects/packages/sockit/
├── packman.json                                        # profile: "laravel-package"
├── composer.json                                       # axiomasoft/sockit-monorepo
├── pint.json / rector.php / phpstan.neon
├── packages/
│   ├── core/                                           # axiomasoft/sockit (SocKit\)
│   │   ├── composer.json                               # requires axiomasoft/packman, packman-{plugins,panels,schema}
│   │   ├── config/sockit.php
│   │   ├── database/migrations/{core,reactions,posts,groups}/
│   │   ├── resources/contracts/{php-api.json,fixtures/}
│   │   └── src/
│   │       ├── Actors/                                 # ActorRef, ActorContext, ActorContextResolver SPI
│   │       ├── Audience/                               # Scope, AudienceSpec, AudienceEvaluator (allOf/anyOf/exclude), PrivacyFloor
│   │       ├── Configuration/SockitOptions.php         # implements Packman\Config\Contracts\OptionsFactory
│   │       ├── Database/SockitSchemaSetProvider.php    # implements Packman\Schema\Contracts\SchemaSetProvider
│   │       ├── Operations/                             # CommandEnvelope, OperationOutcome, Receipts, CAS
│   │       ├── Panels/{SockitPanelProvider,SockitPanelBuilder}.php
│   │       ├── Surfaces/                               # SurfaceProvider, Surface, SurfaceRef, Incarnation
│   │       ├── Support/                                # HostEntityRef, ResourceRef, Revision, CommandKey, UUIDv7
│   │       ├── SocKitServiceProvider.php               # thin provider using RegistersPackageRegistrars
│   │       └── Modules/ (internal plugins implementing Packman\Plugins\Contracts\ExtensionPlugin)
│   │           ├── Reactions/ReactionsPlugin.php       # capabilities: sockit.target-reactions; tables: reactions, reaction_counts
│   │           ├── Posts/PostsPlugin.php               # capabilities: sockit.posts, sockit.feed; optional: sockit.target-reactions
│   │           └── Groups/GroupsPlugin.php             # capabilities: sockit.groups; contributes Group(id) Audience evaluator
│   ├── testkit/                                        # axiomasoft/sockit-testkit (extends Packman\Testkit\PackageTestCase)
│   ├── forum/                                          # axiomasoft/sockit-forum (P7 optional module)
│   └── chatom-bridge/                                  # axiomasoft/sockit-chatom (P8 link-only announcement projection)
```

---

## 3. Exact `packman` Primitives Wired Into `sockit`

1. **Internal Modules via `packman-plugins`:**
   - `ReactionsPlugin` (`id: axiomasoft.sockit.reactions`), `PostsPlugin` (`id: axiomasoft.sockit.posts`), and `GroupsPlugin` (`id: axiomasoft.sockit.groups`) implement `Packman\Plugins\Contracts\ExtensionPlugin`, compiled deterministically by `PluginGraphCompiler` with zero I/O during `register()`.
2. **Headless Storage Panels via `packman-panels`:**
   - `SockitPanelProvider` + `SockitPanelBuilder` compile immutable `PanelDescriptor` / storage isolation definitions during `booting()` (`PanelRegistry::freeze()`).
3. **Configuration via `SockitOptions` (`Packman\Config`):**
   - `final readonly class SockitOptions implements OptionsFactory` validating `$tenantResolver`, `$actorResolver`, `$contourResolver`, `$audienceResolver`, `$enabledModules`, `$panelProviders`, `$receiptTtlSeconds`, and `$outboxBatchSize` via `ConfigurationValidator` / `JournalingConfigReader`.
4. **Declarative Schema via `SockitSchemaSetProvider` (`packman-schema`):**
   - Declares `SchemaSet`s for `sockit-core` (`Platform`), `sockit-reactions`, `sockit-posts`, and `sockit-groups` (`Module`), enforcing 63-byte PostgreSQL `ConstraintNames::shorten()`.
5. **Scaffolding Commands Executed at Stage 7:**
   ```bash
   bin/packman new:monorepo sockit --profile=laravel-package
   bin/packman make:package packages/core --name="axiomasoft/sockit" --namespace="SocKit"
   bin/packman make:package packages/testkit --name="axiomasoft/sockit-testkit" --namespace="SocKit\\Testkit"
   bin/packman make:options Sockit --package=packages/core
   bin/packman make:plugin reactions --package=packages/core --path=src/Modules/Reactions
   bin/packman make:plugin posts --package=packages/core --path=src/Modules/Posts
   bin/packman make:plugin groups --package=packages/core --path=src/Modules/Groups
   bin/packman make:panel SockitPanel --package=packages/core
   bin/packman make:schema-set sockit-core --scope=Central --lifecycle=Platform
   bin/packman gate && bin/packman php-api --generate && bin/packman ai:sync
   ```
