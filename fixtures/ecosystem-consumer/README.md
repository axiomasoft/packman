# Local ecosystem compatibility check

From the Packman checkout, run:

```sh
./bin/php84 python3 scripts/verify-ecosystem.py
```

PHP 8.4, Composer, Git, SQLite, Packman's installed development dependencies and
the nine sibling checkouts are required. Commit all source changes before running;
the command rejects dirty source roots. The runner accepts
`--workspace /absolute/path/to/packages` when they are elsewhere.
It does not modify those checkouts or use package symlinks. Owner runtime packages
are ZIP archives produced by `git archive` from the recorded commits; Composer
metapackages remain dependency declarations. Source fallback is rejected. Consumer
versions are explicitly development snapshots; only Packman is tested as the
`0.1.2` release candidate. Public dependency metadata comes from existing owner
lockfiles, reusing Composer's normal download cache when available and retaining
public download URLs otherwise. Packagist supplies any missing dependencies.

Each run creates a new `build/ecosystem-*` directory containing Composer logs,
archives with SHA-256 receipts, exact owner references, the installed Laravel
applications and `receipt.json`. A supplied `--work-dir` must be a new directory.
Use `--packman-ref <commit>` to select another candidate. The normal baselines are
the existing `v0.1.1` Packman tag and Vaulter commit
`9c76f96b53430dae697aaf02f0369337e10ceed5`; `--baseline-vaulter-ref` can select
another existing Vaulter baseline. Both Vaulter archive references and hashes
are recorded even though their development version label is the same.

The command verifies:

- The current local Packman ownership and attributes-architecture gates against
  all ten source repositories:
  `php bin/packman gate --root=<repository> --only=ownership --only=attributes-architecture --json`.
  Both gates must pass, including Internal ownership and complete API/SPI/Internal
  annotations. The tool comes from the current checkout, rather than an older
  consumer snapshot. Each source SHA and gate report is recorded separately.
  Explicit same-owner groups remain governed by each repository's declarations;
  external Packman internals remain private.
- Ordinary Laravel package discovery after Composer installation.
- A minimal Packman/plugins application with no database configuration, no schema
  add-on and no Filament. Two applications remain alive in one PHP process with
  different options and plugin activation; a retained first-host service still
  works after the second application boots. Repeat registration keeps its graph.
- Joint installation of Chatom, Vaulter, AzGuard, Tenant, Sockit, Corex, Billium,
  Commerz and Proxyma, including Corex Access, Tenancy and Media and their actual
  dependencies. Two live hosts choose different extensions and open no database
  connections. Real inventory allocation and Corex/Tenant context restoration
  run without a database. Enumerating public Doctor/Health registries must produce
  unique check identities in both hosts and preserve the first host's registry.
  No financial operation is executed.
- A real SQLite-backed Corex Media operation: two media records share one Vaulter
  blob and two ownership pins; replay is idempotent; reading returns the original
  file bytes. The host explicitly chooses and runs the schema on SQLite.
- An existing Corex application installed with Packman `v0.1.1` and the baseline
  Vaulter snapshot: store records and bytes, update Packman to the candidate and
  Vaulter to its current commit, rerun the same
  migrations and read the same IDs and file bytes. The application directory,
  SQLite file and storage directory survive the update. Current consumer
  snapshots apart from Vaulter remain fixed. Installation receipts must prove that
  Vaulter's actual source reference changed. This checks the shared and Vaulter
  registration/configuration/migration upgrade; it is not a claim of tagged
  consumer release-to-release coverage.

The test host supplies its own account resolver and explicitly declares shared
facility ownership for Tenant's `laravel-scoped` profile. It does not weaken the
physical PostgreSQL profile or install a new Sockit Laravel layer. PostgreSQL-only
product persistence, physical database cloning and financial behavior are outside
this fixture. Local archives do not by themselves verify remote downloads or
publication of consumer versions; the release installation check is separate.
AzGuard's [source repository](https://github.com/axiomasoft/azguard) is public.
Source availability and discovery of nested Composer packages are separate concerns.
