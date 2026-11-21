# Changelog

## 1.0.0 - Unreleased

- Initial extraction of `Packman\Modules\*` into `axiomasoft/packman-modules`, preserving the deterministic compiler pipeline, lifecycle FSM, `mod_*` schema ownership claims, and PHPStan boundary rules while parameterizing discovery and storage options via `ModulesOptions`.
- Reject a non-array compiled module registry and reload it when the compiled file changes, including when OPcache ignores timestamps.
- Decode `installed.json` once per file contents while compiling or explaining modules.
- Reject `modules:repair` targets that are not Composer package names.
- Treat a blank `modules:why --scope` as absent, and refuse the command when `--scope` and `--account` disagree.
- Return a failed `modules:compile` when the compiled registry cannot be written.
