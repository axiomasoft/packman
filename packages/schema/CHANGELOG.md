# Changelog

## Unreleased

- Skip module ownership collection when a connection has an empty migration plan, instead of spreading an empty batch list.
- Treat a blank `--database` on the schema commands as the default connection.
- Initial `axiomasoft/packman-schema` extraction from the unified Packman kernel design.
