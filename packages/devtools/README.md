# axiomasoft/packman-devtools

Shared **dev-only** engineering toolchain for the Packman ecosystem:

> **Never depend on `axiomasoft/packman-devtools` in production `require`.** Install in `require-dev` only.

## CLI Commands (`bin/packman`)

- `bin/packman gate [--only=...]`: Runs the 16 architecture, boundary, contract, and quality gates against `packman.json`.
- `bin/packman php-api [--check|--write]`: Scans `#[Api]`, `#[Spi]`, and `#[Internal]` attributes and checks/writes `resources/contracts/php-api.json`.
- `bin/packman error-codes [--check|--write]`: Scans error codes, descriptors, and `ProblemDefinitionProvider` implementations.
- `bin/packman config-schema [--check|--write]`: Generates/validates JSON config schemas from `ConfigSection` implementations.
- `bin/packman make <profile> <name>` scaffolds a PHP library, Laravel package, Packman plugin, or Packman module. Pass `--minimal` to either extension profile to generate just the registration contract and its test. Add configuration, database settings, or schema ownership when the extension needs them.
- `bin/packman split [--dry-run] [--only=...]`: Orchestrates verified subtree splits from `split.json`.
- `bin/packman ai:sync [--dry-run|--check]`: Synchronizes `.claude/`, `.codex/agents/*.toml`, `.gemini/`, and `AGENTS.md` from canonical `ai/` sources (and fails on missing/drifted outputs when checking).
