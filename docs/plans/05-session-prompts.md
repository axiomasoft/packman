# 05. Execution Strategy & Ready-to-Use Prompts for Next Sessions

---

## 1. Choosing the Best Model & Execution Strategy

| Phase | Recommended Mode / Tooling | Why This Works Best |
|:--|:--|:--|
| **Stage 1: Building `packman` (Waves 1.1–1.4)** | **New Session on `Orchestrator (Argon)`** + `swarm` / `run_workflow` (using `Hydrogen` or `Sonnet`/`Opus` for disjoint packages) + **`gpt-6-astra` via `codex exec`** as external reviewer | `packman` is now **completely specified** in `docs/plans/00..05` and `docs/plans/projects/*`. `Orchestrator (Argon)` enforces plan-review checkpoints and lets you execute Wave 1.1 -> 1.2 -> 1.3 -> 1.4 without drifting, while `Hydrogen (High)` / `Claude Opus/Sonnet` workers copy and surgically adapt each subpackage in parallel and `gpt-6-astra` audits every wave read-only for purity, leaks, and test coverage. |
| **Stages 2–7: Rolling out across the 9 Sibling Repos** | **`run_workflow`** (1 isolated fresh-context agent per repository in the 7-stage dependency order: Pilot `tenant` & `proxyma` -> `azguard` & `vaulter` -> `commerz` & `chatom` -> `billium` -> `modular-platform` -> `sockit`) | Each repository has a self-contained spec in `docs/plans/projects/01-modular-platform.md .. 09-sockit.md`. A fresh-context worker per repo avoids context pollution between unrelated domains and proves each repo independently (`lint`, `analyse`, `test`, `test:pg`, `gate`, `php-api`, clean consumer install, `gpt-6-astra` review). |

> **Built-in Codex Review Pattern (for both stages):**
> Run `codex -a never exec --sandbox read-only --model gpt-6-astra -c 'model_reasoning_effort="xhigh"' ...` at the end of every wave / every repository migration before marking it green.

---

## 2. Prompt #1 — Stage 1: Implement `packman` Reference Monorepo (New Session)

Copy and paste this prompt into a fresh session opened in `/home/vostrikov/projects/packages/packman` on **Orchestrator (Argon)**:

```text
/goal Implement the `packman` ecosystem foundation monorepo in `/home/vostrikov/projects/packages/packman` strictly following the approved architectural plans and Copy-First Runbook already stored in this repository.

Read these documents first before writing any code:
- `README.md`
- `docs/plans/01-packman-monorepo-architecture-and-tree.md`
- `docs/plans/04-execution-runbook-and-copy-map.md`
- `docs/plans/projects/01-modular-platform.md`

Critical constraints (MUST NEVER be violated):
1. **Zero downstream host mentions inside `packman` code/identifiers/configs/tests.** `packman` is the universal Layer-0/Layer-1 ecosystem foundation; all sibling packages and modular platforms will consume `packman`.
2. **Copy-First, Surgical-Refactor-Second:** Do NOT rewrite algorithms from scratch. Copy exact battle-tested implementations and their existing unit/feature/PG tests from the sibling ecosystem packages per `docs/plans/04-execution-runbook-and-copy-map.md`, then surgically refactor namespaces to `Packman\*` and parameterize hardcoded strings.
3. **Preserve Reference `Modules` Loader 100% (`packages/modules`):** Copy all 111 PHP files from `<platform>/packages/core/src/Modules/**`, its 3 `mod_*` migrations, `extension.neon` (`packmanModules`), and all `Modules` tests/stubs. Replace the 29 non-`Modules` host imports with `Packman\*` kernel contracts and `Packman\Schema\*`, and move all 13 hardcoded host strings into `ModulesOptions` defaults. Never alter the compiler pipeline, topological sort, lifecycle state machine, or `mod_records` claims logic.
4. **8-Package Architecture:**
   - `packages/core` (`axiomasoft/packman`, `Packman\`, pure PHP 8.4+ base in `require` so `php-library` consumers never pull Laravel)
   - `packages/modules` (`axiomasoft/packman-modules`, `Packman\Modules\`)
   - `packages/plugins` (`axiomasoft/packman-plugins`, `Packman\Plugins\`)
   - `packages/schema` (`axiomasoft/packman-schema`, `Packman\Schema\`)
   - `packages/panels` (`axiomasoft/packman-panels`, `Packman\Panels\`)
   - `packages/filament` (`axiomasoft/packman-filament`, `Packman\Filament\`)
   - `packages/testkit` (`axiomasoft/packman-testkit`, `Packman\Testkit\`, `require-dev` only)
   - `packages/devtools` (`axiomasoft/packman-devtools`, `Packman\DevTools\`, `require-dev` only — CLI `bin/packman`, `StubStore`, all 16 quality gates, Rector rules, `split`, `ai:sync`)
5. **Execution & Orchestration:**
   - Execute in the 4 waves defined in `docs/plans/04-execution-runbook-and-copy-map.md` (Wave 1.1 Kernel -> Wave 1.2 Add-ons in parallel via `swarm`/`run_workflow` -> Wave 1.3 Testkit, DevTools, AI Pack, and 3 Consumer Fixtures -> Wave 1.4 Full QA).
   - At the end of each wave and before completion, run a read-only architectural, security, and test-completeness review using `codex -a never exec --sandbox read-only --model gpt-6-astra -c 'model_reasoning_effort="xhigh"'` (and fix any Blocker/Major findings).
   - Verify 100% of checks: zero legacy host-branding matches in `packages/ schemas/ ai/ bin/`, `composer lint:check`, `composer analyse` (PHPStan max), `composer refactor:check`, `composer gate` (all 16 gates), `composer php-api`, `composer test` (SQLite), `composer test:pg` (isolated PG `_test` DB), and clean `composer install` + tests across all 3 `fixtures/` (`standalone-php-consumer`, `standalone-laravel-consumer`, `modular-platform-consumer`).
```

---

## 3. Prompt #2 — Stages 2–7: Ecosystem Rollout Across All 9 Repositories (After Stage 1 Completes)

Run this prompt only after `packman` (Prompt #1) passes all tests, gates, and `gpt-6-astra` verification:

```text
/goal Execute Stages 2–7 of `docs/plans/02-ecosystem-rollout-and-alignment-plan.md`, aligning all 9 sibling repositories (`tenant`, `proxyma`, `azguard`, `vaulter`, `commerz`, `chatom`, `billium`, `modular-platform`, `sockit`) to consume the finished `packman` foundation monorepo at `/home/vostrikov/projects/packages/packman`.

Rules & Orchestration:
1. Read `docs/plans/02-ecosystem-rollout-and-alignment-plan.md` and ALL 9 per-project specs in `docs/plans/projects/01-modular-platform.md` .. `09-sockit.md`.
2. Process repositories strictly in dependency/risk stages using `run_workflow` with **one fresh-context subagent per repository** (on a dedicated git branch per repo so every change is cleanly reversible):
   - Stage 2 (Pilots): `tenant` (`projects/07-tenant.md`) and `proxyma` (`projects/06-proxyma.md`)
   - Stage 3: `azguard` (`projects/05-azguard.md`) and `vaulter` (`projects/04-vaulter.md`)
   - Stage 4: `commerz` (`projects/02-commerz.md`) and `chatom` (`projects/03-chatom.md`)
   - Stage 5: `billium` (`projects/08-billium.md`)
   - Stage 6: `modular-platform` (`projects/01-modular-platform.md`)
   - Stage 7: `sockit` (`projects/09-sockit.md` — scaffold via `bin/packman`)
3. Each per-repository agent MUST:
   - Point local path repositories (or versioned constraint) to `packman/packages/*` as appropriate for local monorepo verification.
   - Apply every file replacement, `*Options` refactoring, plugin/panel alignment, `SchemaSet` migration, composer naming fix, and AI/VCS cleanup listed in its `docs/plans/projects/*.md` spec.
   - Run `bin/packman ai:sync` and `bin/packman php-api --check`.
   - Pass all checks on that repository: `composer lint:check`, `composer analyse`, `composer refactor:check`, `composer gate`, `composer test`, and isolated `composer test:pg`.
   - Run a final read-only audit per repo via `codex -a never exec --sandbox read-only --model gpt-6-astra` verifying zero unintended breaking BC changes and zero host-product coupling in standalone packages.
4. Halt between stages if any pilot or stage uncovers a flaw in `packman` itself; fix and re-verify `packman` before advancing to the next stage.
```
