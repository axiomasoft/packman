# Contributing

Packman is a PHP 8.4 monorepo. The public packages are `axiomasoft/packman`, `packman-schema`, `packman-plugins`, `packman-modules`, `packman-panels`, `packman-filament`, `packman-testkit`, and `packman-devtools`.

## Local checks

Use `./bin/php84` so Composer and the test runner see PHP 8.4:

```sh
./bin/php84 --check
./bin/php84 composer install
make ci
```

`make ci` runs Pint, PHPStan level max, Rector, the 16 architecture gates, the public API / error-code / config-schema manifests, AI sync, `composer audit`, both database lanes, and the consumer fixtures. SQLite tests also run in parallel with `make test-parallel`.

PostgreSQL tests expect a database named `packman_test` on `127.0.0.1:54329`, user `skm`, password `skm_dev`. The name must end in `_test`. `PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1` is set by `phpunit.xml`.

## Boundaries

Every type in `packages/*/src` declares `#[Api]`, `#[Spi]`, or `#[Internal]`. Public signatures do not expose internal types. Domain code does not call `config()`, `env()`, or PHP superglobals. Package service providers stay thin and register through `PackageRegistrar`.

Regenerate a manifest only when its source changed:

```sh
./bin/php84 bin/packman php-api --write
./bin/php84 bin/packman error-codes --write
./bin/php84 bin/packman config-schema --write
```

The release feed in `resources/distribution/packages.json` is generated from the package manifests:

```sh
python3 scripts/release-repository.py --version 0.1.2 --tag v0.1.2
python3 scripts/release-repository.py --version 0.1.2 --tag v0.1.2 --check
```

## Reports

Open an issue for a defect or an API question: https://github.com/axiomasoft/packman/issues

Send a vulnerability report privately. See [SECURITY.md](SECURITY.md). This repository does not use Dependabot. Advisories are checked with `composer audit`.
