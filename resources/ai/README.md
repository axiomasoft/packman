# Canonical Packman AI Pack

This directory is the canonical source of truth for Packman AI coding rules, reusable skills, and agent definitions, synchronized into tool-specific targets by `EcosystemAiSynchronizer` (`./bin/php84 bin/packman ai:sync`).

## Layout

- `ai/manifest.json` — Pack identity and version (`name`, `version`) consumed by `EcosystemAiSynchronizer`.
- `ai/rules/*.md` — Canonical architectural and engineering rules, emitted to `.claude/rules/*.md` and `.gemini/rules/*.md`.
- `ai/agents/*.md` — Canonical agent definitions with YAML frontmatter (`name`, `description`, `model`, `model_reasoning_effort`, `sandbox_mode`, `skills`), emitted to `.claude/agents/*.md` and `.codex/agents/*.toml` (with Codex v0.169+ `[skills]\nenabled = [...]` `BundledSkillsConfig` tables).
- `ai/skills/packman-*/SKILL.md` — Canonical Packman skills referenced by agent `[skills]` sections (kept canonical in `ai/skills/` and never duplicated by `ai:sync`).

## Synchronization

```bash
./bin/php84 bin/packman ai:sync
./bin/php84 bin/packman ai:sync --check   # or --dry-run; fails on missing/drifted files
./bin/php84 composer ai:sync              # dry-run verification
```

Do not edit generated files under `.claude/`, `.codex/agents/`, `.gemini/`, or `AGENTS.md` directly (except local `*-reviewer-overrides.md` files, which `ai:sync` preserves).
