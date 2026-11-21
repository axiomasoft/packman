# Install a versioned Packman release

Packman 0.1.2 is distributed through a public, versioned Composer repository in
its existing monorepository. No credentials, workspace checkout or symlinks are
needed. These packages are not published on Packagist. A VCS repository for the
monorepository alone cannot discover its nested Composer packages.

From a PHP 8.4+ application, register the release repository and require the
packages needed by the selected feature:

```sh
composer config repositories.packman composer https://raw.githubusercontent.com/axiomasoft/packman/v0.1.2/resources/distribution/packages.json
composer require axiomasoft/packman:0.1.2
```

For application-scoped plugins in a Laravel application:

```sh
composer require axiomasoft/packman-plugins:0.1.2
```

Add the other packages only for the capability you use:

```sh
composer require axiomasoft/packman-schema:0.1.2
composer require axiomasoft/packman-modules:0.1.2
composer require axiomasoft/packman-panels:0.1.2
composer require axiomasoft/packman-filament:0.1.2
```

`axiomasoft/packman-testkit` and `axiomasoft/packman-devtools` are development
tools. Require them in `require-dev`, not in the production runtime of an
application. Devtools publishes the `packman` executable used by the quality
gates and the scaffolding commands.

Laravel's ordinary Composer discovery registers the installed adapters. The
kernel itself requires only PHP and `psr/clock`. Plugin support adds the declared
Laravel components, without requiring a database package. Schema, modules,
panels and Filament remain separate selections. Requiring Filament installs its
own declared dependencies. Each package README states the public entry point:
[kernel](../../packages/core/README.md), [plugins](../../packages/plugins/README.md),
[modules](../../packages/modules/README.md), [schema](../../packages/schema/README.md),
[panels](../../packages/panels/README.md), and [Filament](../../packages/filament/README.md).

The feed preserves each package's actual `require`, `conflict`, `suggest` and
discovery metadata. All eight package versions refer to the same `v0.1.2` tag of
`https://github.com/axiomasoft/packman`. Each installed package contains a full
monorepository archive; Composer autoload, executable and PHPStan include paths
point into that package's own `packages/<directory>/` subtree. This trades some
disk duplication for installation from the existing repository, without creating
split repositories. Use the public PHP contracts and code-relative resources;
a downstream assumption that source lives directly under
`vendor/axiomasoft/packman/src` is incompatible with this distribution layout.

`composer.lock` records the resolved `0.1.2` version, public source URL and tag
reference. For a source checkout, the resolved commit can also be checked:

```sh
composer reinstall axiomasoft/packman --prefer-source
composer show axiomasoft/packman
git -C vendor/axiomasoft/packman rev-parse HEAD
git ls-remote https://github.com/axiomasoft/packman.git 'refs/tags/v0.1.2*'
```

Compare the checkout SHA with the tag's peeled commit for an annotated tag.
Archive installations have no `.git` directory; use the lockfile source/dist
reference and the public tag instead. An independently checked release receipt
must record the remote tag commit as well as the Composer version.

## Repository maintenance

Regenerate and verify release metadata before publishing the tag:

```sh
python3 scripts/release-repository.py --version 0.1.2 --tag v0.1.2
python3 scripts/release-repository.py --version 0.1.2 --tag v0.1.2 --check
python3 -m unittest discover -s tests/scripts -p 'test_release_repository.py'
```

The generated feed refers to its immutable release tag rather than embedding
its own future commit SHA. Publication order is: finish local verification,
commit and push `main`, verify remote `main`, publish `v0.1.2`, then install from
the public versioned feed in a clean application. Do not move a published tag.
For a later release, generate a new version and use its corresponding feed URL.
Each package manifest records the repository homepage, issue tracker, and security policy.
Those fields travel with the generated feed. The already published `v0.1.2` tag keeps the feed that was current when that tag was created.

This feed distributes Packman only. Other ecosystem owners may still require
explicit repositories with reviewed, pinned source metadata; dependency-level
repositories are not inherited by a consuming Composer root. A successful
Packman install does not claim that those owners have published stable releases
or that private sources are anonymously accessible.
