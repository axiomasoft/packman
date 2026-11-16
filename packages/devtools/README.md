# axiomasoft/packman-devtools

Shared **dev-only** engineering toolchain for the Packman ecosystem:

> **Never depend on `axiomasoft/packman-devtools` in production `require`.** Install in `require-dev` only.

## CLI Commands (`bin/packman`)

- `bin/packman gate [--only=...]`: Runs the 16 architecture, boundary, contract, and quality gates against `packman.json`.
- `bin/packman php-api [--check|--write]`: Scans `#[Api]`, `#[Spi]`, and `#[Internal]` attributes and checks/writes `resources/contracts/php-api.json`.
- `bin/packman error-codes [--check|--write]`: Scans error codes, descriptors, and `ProblemDefinitionProvider` implementations.
- `bin/packman config-schema [--check|--write]`: Generates/validates JSON config schemas from `ConfigSection` implementations.
- `bin/packman make <profile> <name>`: Scaffolds packages using `StubStore` (`php-library`, `laravel-package`, `packman-plugin`, `packman-module`).
- `bin/packman split [--dry-run] [--only=...]`: Orchestrates verified subtree splits from `split.json`.
- `bin/packman ai:sync [--dry-run]`: Synchronizes `.claude/`, `.codex/agents/*.toml`, `.gemini/`, and `AGENTS.md` from canonical `ai/` sources.
