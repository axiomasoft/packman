<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Packman\DevTools\Gates\Runner\GateRunner;
use Packman\DevTools\Tests\Support\TempWorkspace;

it('rejects annotated internal imports without executing the target dependency', function (): void {
    $ws = new TempWorkspace('packman-attribute-ownership-');
    $loader = new ClassLoader($ws->path('external-vendor'));

    try {
        $ws->seedSchemasFromRepo(dirname(__DIR__, 4));
        $caller = $ws->seedCleanMonorepoPackage('acme/caller', 'packages/caller', 'Acme\\Caller\\');
        $owner = $ws->seedCleanMonorepoPackage('acme/owner', 'packages/owner', 'Acme\\Owner\\');
        $ws->writePackmanJson([$caller, $owner]);
        $ws->write('packages/owner/src/Support/Hidden.php', <<<'PHP'
<?php
namespace Acme\Owner\Support;
use Packman\Attributes\Internal as PrivateSurface;
#[PrivateSurface]
final class Hidden extends MissingOptionalIntegration {}
PHP);
        $ws->write('external/HiddenDependency.php', <<<'PHP'
<?php
namespace Acme\Dependency;
#[\Packman\Attributes\Internal]
final class HiddenDependency extends MissingOptionalIntegration {}
PHP);
        $ws->write('packages/caller/src/Consumer.php', <<<'PHP'
<?php
namespace Acme\Caller;
use Acme\Owner\Support\Hidden;
use Acme\Dependency\HiddenDependency;
final class Consumer {}
PHP);
        $loader->addClassMap(['Acme\Dependency\HiddenDependency' => $ws->path('external/HiddenDependency.php')]);
        $loader->register();

        $result = GateRunner::default()->run($ws->root, ['ownership'])[0];

        expect($result->passed)->toBeFalse()
            ->and(implode("\n", $result->violations))->toContain('Acme\Owner\Support\Hidden', 'Acme\Dependency\HiddenDependency')
            ->and(class_exists('Acme\Dependency\HiddenDependency', false))->toBeFalse()
            ->and(class_exists('Acme\Owner\Support\Hidden', false))->toBeFalse();

        $ws->write('packages/caller/src/Consumer.php', <<<'PHP'
<?php
namespace Acme\Caller;
use Acme\Owner\Contracts\SampleService;
final class Consumer {}
PHP);
        expect(GateRunner::default()->run($ws->root, ['ownership'])[0]->passed)->toBeTrue();
    } finally {
        $loader->unregister();
        $ws->cleanup();
    }
});

it('allows declared product internals while keeping external internals private', function (): void {
    $ws = new TempWorkspace('packman-product-owner-');

    try {
        $ws->seedSchemasFromRepo(dirname(__DIR__, 4));
        $caller = $ws->seedCleanMonorepoPackage('acme/caller', 'packages/caller', 'Acme\\Caller\\');
        $owner = $ws->seedCleanMonorepoPackage('acme/owner', 'packages/owner', 'Acme\\Owner\\');
        $ws->writePackmanJson([$caller, $owner]);
        $manifest = json_decode((string) file_get_contents($ws->path('packman.json')), true, 512, JSON_THROW_ON_ERROR);
        $manifest['gates']['internal_ownership_groups'] = ['product' => ['acme/caller', 'acme/owner']];
        $ws->writeJson('packman.json', $manifest);
        $ws->write('packages/owner/src/Internal/Hidden.php', <<<'PHP'
<?php
namespace Acme\Owner\Internal;
#[\Packman\Attributes\Internal]
final class Hidden extends MissingOptionalIntegration {}
PHP);
        $ws->write('packages/caller/src/Consumer.php', <<<'PHP'
<?php
namespace Acme\Caller;
use Acme\Owner\Internal\Hidden;
final class Consumer {}
PHP);
        expect(GateRunner::default()->run($ws->root, ['ownership'])[0]->passed)->toBeTrue();
        expect(GateRunner::default()->run($ws->root, ['package-graph'])[0]->passed)->toBeFalse();
        $composer = json_decode((string) file_get_contents($ws->path('packages/caller/composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $composer['require']['acme/owner'] = '^1.0';
        $ws->writeJson('packages/caller/composer.json', $composer);
        expect(GateRunner::default()->run($ws->root, ['package-graph'])[0]->passed)->toBeTrue();

        $ws->write('packages/caller/src/Consumer.php', <<<'PHP'
<?php
namespace Acme\Caller;
use Acme\Owner\Internal\Hidden;
use Packman\Doctor\Checks\ConfigValidCheck;
final class Consumer {}
PHP);
        $result = GateRunner::default()->run($ws->root, ['ownership'])[0];
        expect($result->passed)->toBeFalse()
            ->and($result->violations)->toHaveCount(1)
            ->and($result->violations[0])->toContain('Packman\Doctor\Checks\ConfigValidCheck')
            ->and(class_exists('Acme\Owner\Internal\Hidden', false))->toBeFalse();
    } finally {
        $ws->cleanup();
    }
});

it('rejects invalid or non-runtime ownership groups', function (mixed $groups): void {
    $ws = new TempWorkspace('packman-invalid-product-owner-');

    try {
        $ws->seedSchemasFromRepo(dirname(__DIR__, 4));
        $caller = $ws->seedCleanMonorepoPackage('acme/caller', 'packages/caller', 'Acme\\Caller\\');
        $owner = $ws->seedCleanMonorepoPackage('acme/owner', 'packages/owner', 'Acme\\Owner\\');
        $dev = $ws->seedCleanMonorepoPackage('acme/devtools', 'packages/devtools', 'Acme\\DevTools\\', layer: 'L4', devOnly: true);
        $external = $ws->seedCleanMonorepoPackage('acme/external', 'vendor/acme/external', 'Acme\\External\\');
        $ws->writePackmanJson([$caller, $owner, $dev, $external]);
        $manifest = json_decode((string) file_get_contents($ws->path('packman.json')), true, 512, JSON_THROW_ON_ERROR);
        $manifest['gates']['internal_ownership_groups'] = $groups;
        $ws->writeJson('packman.json', $manifest);

        $result = GateRunner::default()->run($ws->root, ['ownership'])[0];
        expect($result->passed)->toBeFalse()
            ->and(implode("\n", $result->violations))->toContain('ownership');
    } finally {
        $ws->cleanup();
    }
})->with([
    'null' => [null],
    'string' => ['product'],
    'list' => [[['acme/caller', 'acme/owner']]],
    'single member' => [['product' => ['acme/caller']]],
    'duplicate member' => [['product' => ['acme/caller', 'acme/caller']]],
    'overlapping groups' => [['first' => ['acme/caller', 'acme/owner'], 'second' => ['acme/caller', 'acme/owner']]],
    'unknown dependency' => [['product' => ['acme/caller', 'axiomasoft/packman']]],
    'external installation' => [['product' => ['acme/caller', 'acme/external']]],
    'development package' => [['product' => ['acme/caller', 'acme/devtools']]],
    'non-string member' => [['product' => ['acme/caller', 42]]],
]);
