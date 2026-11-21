# Changelog

All notable changes to `axiomasoft/packman-devtools` will be documented in this file.

## Unreleased

- Reuse parsed PHP sources across quality gates without sharing a mutable AST.
- Resolve names once per source file and reuse that tree in the surface and error scanners.
- Fail a quality gate when a package `composer.json` is empty, unreadable, or not a JSON object.
- Refuse a scaffold file whose path resolves outside the destination directory.
- Refuse a scaffold stub path that leaves the stub root.
- Initial monorepo release delivering the 16 quality gates, `SurfaceScanner`, `ErrorSourceScanner`, `ConfigSchemaGenerator`, `StubStore` scaffolding for all 4 profiles, `PackmanRectorSet` + custom rules, `MonorepoSplitter`, `EcosystemAiSynchronizer`, and `bin/packman`.
