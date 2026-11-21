# Changelog

All notable changes to `axiomasoft/packman` will be documented in this file.

## [1.0.0] - Unreleased
- Initial release of the unified `Packman\` kernel (`Attributes`, `Foundation`, `Context`, `Data`, `Config`, `Errors`, `Doctor`, `Events`, `Features`, `Settings`).
- Remove a partial definitions-cache file when an atomic replace fails, and drop the OPcache entry when the cache is cleared.
- Compare doctor migration status by the full migration name, and report an unreadable migration repository instead of failing the command.
- Reject scalars and objects in `JsonDocumentCast` on write as well as on read.
- Keep `packman:config:validate` successful when no configuration report sections are registered.
- Reject a logical table name that is not lower-case snake case, and report a missing package config file before loading it.
- Report a definitions-cache directory that cannot be created, without turning the mkdir warning into a foreign exception.
