<?php

declare(strict_types=1);

use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Gates\Architecture\AttributesArchitectureGate;
use Packman\DevTools\Gates\Architecture\ModuleLayoutGate;
use Packman\DevTools\Gates\Architecture\OwnershipGate;
use Packman\DevTools\Gates\Architecture\PackageGraphGate;
use Packman\DevTools\Gates\Boundaries\ConfigCompositionGate;
use Packman\DevTools\Gates\Boundaries\ProviderSizeGate;
use Packman\DevTools\Gates\Boundaries\RuntimeGlobalsGate;
use Packman\DevTools\Gates\Boundaries\TableOwnershipGate;
use Packman\DevTools\Gates\Boundaries\TableWriteOwnershipGate;
use Packman\DevTools\Gates\Contracts\ErrorRegistryGate;
use Packman\DevTools\Gates\Contracts\ExtensionContractGate;
use Packman\DevTools\Gates\Contracts\PhpApiGate;
use Packman\DevTools\Gates\Contracts\SchemaGate;
use Packman\DevTools\Gates\Quality\NamingGate;
use Packman\DevTools\Gates\Quality\PackageDocsGate;
use Packman\DevTools\Gates\Quality\QualityCeilingGuard;
use Packman\DevTools\Gates\Runner\GateRunner;
use Packman\DevTools\Generators\Contracts\ApiManifestGenerator;
use Packman\DevTools\Tests\Support\TempWorkspace;

function packmanRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

/** @return array<string, GateResult> */
function runGateIds(TempWorkspace $ws, string ...$gateIds): array
{
    $results = GateRunner::default()->run($ws->root, array_values($gateIds));
    $indexed = [];
    foreach ($results as $result) {
        $indexed[$result->gateId] = $result;
    }

    return $indexed;
}

it('passes all 16 gates on a valid fixture monorepo when contracts match', function (): void {
    $ws = new TempWorkspace;

    try {
        $ws->seedSchemasFromRepo(packmanRepoRoot());
        $pkgA = $ws->seedCleanMonorepoPackage('acme/core', 'packages/core', 'Acme\\Core\\', 'php-library', 'L1');
        $pkgB = $ws->seedCleanMonorepoPackage('acme/feature', 'packages/feature', 'Acme\\Feature\\', 'laravel-package', 'L2');
        $ws->writePackmanJson([$pkgA, $pkgB]);

        (new ApiManifestGenerator)->sync($ws->root, [
            ['name' => $pkgA['name'], 'path' => $pkgA['path']],
            ['name' => $pkgB['name'], 'path' => $pkgB['path']],
        ], write: true);

        $results = GateRunner::default()->run($ws->root);
        expect($results)->toHaveCount(16);

        foreach ($results as $result) {
            expect($result->passed)->toBeTrue("Gate [{$result->gateId}] failed: ".implode('; ', $result->violations));
        }
    } finally {
        $ws->cleanup();
    }
});

it('catches positive and negative scenarios for architecture gates (package-graph, module-layout, ownership, attributes)', function (): void {
    $ws = new TempWorkspace;

    try {
        $ws->seedSchemasFromRepo(packmanRepoRoot());
        $core = $ws->seedCleanMonorepoPackage('acme/core', 'packages/core', 'Acme\\Core\\', 'php-library', 'L1');
        $dev = $ws->seedCleanMonorepoPackage('acme/dev', 'packages/dev', 'Acme\\Dev\\', 'laravel-package', 'L4', true);
        $addon = $ws->seedCleanMonorepoPackage('acme/addon', 'packages/addon', 'Acme\\Addon\\', 'laravel-package', 'L2');

        // 1) package-graph: runtime requiring dev-only + upward layer + cycle
        $ws->writeJson('packages/core/composer.json', [
            'name' => 'acme/core',
            'description' => 'Core.',
            'type' => 'library',
            'require' => [
                'php' => '^8.4',
                'acme/dev' => '^1.0',
                'acme/addon' => '^1.0',
                'illuminate/support' => '^13.0',
            ],
        ]);
        $ws->write('packages/addon/src/Internal/Secret.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Addon\Internal;

use Packman\Attributes\Internal;

#[Internal]
final class Secret {}

PHP);
        $ws->write('packages/core/src/WeirdDir/BadClass.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Core\WeirdDir;

use Acme\Addon\Internal\Secret;

final class BadClass
{
    public function leak(Secret $secret): Secret
    {
        return $secret;
    }
}

PHP);
        $ws->writePackmanJson([$core, $addon, $dev]);

        $res = runGateIds(
            $ws,
            (new PackageGraphGate)->id(),
            (new ModuleLayoutGate)->id(),
            (new OwnershipGate)->id(),
            (new AttributesArchitectureGate)->id(),
        );

        expect($res['package-graph']->passed)->toBeFalse()
            ->and($res['module-layout']->passed)->toBeFalse()
            ->and($res['ownership']->passed)->toBeFalse()
            ->and($res['attributes-architecture']->passed)->toBeFalse();
    } finally {
        $ws->cleanup();
    }
});

it('catches positive and negative scenarios for boundary gates (config-composition, runtime-globals, provider-size, table-ownership, table-write-ownership)', function (): void {
    $ws = new TempWorkspace;

    try {
        $ws->seedSchemasFromRepo(packmanRepoRoot());
        $alpha = $ws->seedCleanMonorepoPackage('acme/alpha', 'packages/alpha', 'Acme\\Alpha\\', 'laravel-package', 'L2');
        $beta = $ws->seedCleanMonorepoPackage('acme/beta', 'packages/beta', 'Acme\\Beta\\', 'laravel-package', 'L2');
        $ws->writePackmanJson([$alpha, $beta]);

        $ws->write('packages/alpha/src/Data/DomainThing.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Alpha\Data;

use Illuminate\Support\Facades\DB;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class DomainThing
{
    public function run(): void
    {
        $x = config('alpha.key');
        $y = $_GET['id'] ?? $x;
        DB::table('sys_modules')->insert(['name' => $y]);
    }
}

PHP);

        // Oversized service provider > 80 lines
        $lines = array_merge([
            '<?php',
            'declare(strict_types=1);',
            'namespace Acme\Alpha\Laravel;',
            'use Packman\Attributes\Spi;',
            'use Packman\Attributes\SpiKind;',
            "#[Spi(kind: SpiKind::Call, since: '1.0')]",
            'final class AlphaServiceProvider {',
        ], array_fill(0, 80, '    // padding line'), ['}']);
        $ws->write('packages/alpha/src/Laravel/AlphaServiceProvider.php', implode("\n", $lines)."\n");

        // Conflicting migration table ownership across alpha & beta
        $migBody = <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

Schema::create('shared_entities', static function (): void {});

PHP;
        $ws->write('packages/alpha/database/migrations/2026_11_01_000001_create_shared.php', $migBody);
        $ws->write('packages/beta/database/migrations/2026_11_01_000002_create_shared_dup.php', $migBody);

        $res = runGateIds(
            $ws,
            (new ConfigCompositionGate)->id(),
            (new RuntimeGlobalsGate)->id(),
            (new ProviderSizeGate)->id(),
            (new TableOwnershipGate)->id(),
            (new TableWriteOwnershipGate)->id(),
        );

        expect($res['config-composition']->passed)->toBeFalse()
            ->and($res['runtime-globals']->passed)->toBeFalse()
            ->and($res['provider-size']->passed)->toBeFalse()
            ->and($res['table-ownership']->passed)->toBeFalse()
            ->and($res['table-write-ownership']->passed)->toBeFalse();
    } finally {
        $ws->cleanup();
    }
});

it('catches positive and negative scenarios for contract and quality gates (php-api, error-registry, extension-contract, schema-gate, package-docs, naming, quality-ceiling)', function (): void {
    $ws = new TempWorkspace;

    try {
        $ws->seedSchemasFromRepo(packmanRepoRoot());
        $pkg = $ws->seedCleanMonorepoPackage('acme/bad-pkg', 'packages/bad', 'Acme\\Bad\\', 'laravel-package', 'L2');
        $ws->writePackmanJson([$pkg]);

        // 1) php-api: no php-api.json generated -> fails
        // 2) error-registry: invalid wire code + bad URN in provider
        $ws->write('packages/bad/src/Enums/BadErrorCode.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Bad\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

#[Api(since: '1.0')]
enum BadErrorCode: string implements ErrorCode
{
    case InvalidFormat = 'INVALID_WIRE_CODE';

    public function value(): string
    {
        return $this->value;
    }
}

PHP);
        // 3) extension-contract: invalid composer module class + bad PluginManifest id
        $ws->writeJson('packages/bad/composer.json', [
            'name' => 'Bad_Name_Not_Kebab',
            'description' => '',
            'type' => 'library',
            'require' => ['php' => '^8.4'],
            'extra' => ['packman' => ['module' => 'Acme\\Bad\\MissingModule']],
        ]);
        // 4) extension manifest violation + missing strict_types + eval() + @phpstan-ignore
        $ws->write('packages/bad/src/Data/WrongFileName.php', <<<'PHP'
<?php

namespace Acme\Bad\WrongNs;

use Packman\Attributes\Api;
use Packman\Plugins\Data\Plugin\PluginManifest;

#[Api(since: '1.0')]
final class MismatchedClassName
{
    // @phpstan-ignore-next-line
    public function run(): void
    {
        $m = new PluginManifest(id: 'INVALID ID');
        dd($m);
        eval('echo 1;');
    }
}

PHP);
        // 5) schema-gate: duplicate migration timestamps
        $ws->write('packages/bad/database/migrations/2026_11_01_000001_first.php', "<?php\n");
        $ws->write('packages/bad/database/migrations/2026_11_01_000001_second.php', "<?php\n");
        // 6) package-docs: empty README + missing CHANGELOG
        $ws->write('packages/bad/README.md', '');
        @unlink($ws->path('packages/bad/CHANGELOG.md'));

        $res = runGateIds(
            $ws,
            (new PhpApiGate)->id(),
            (new ErrorRegistryGate)->id(),
            (new ExtensionContractGate)->id(),
            (new SchemaGate)->id(),
            (new PackageDocsGate)->id(),
            (new NamingGate)->id(),
            (new QualityCeilingGuard)->id(),
        );

        expect($res['php-api']->passed)->toBeFalse()
            ->and($res['error-registry']->passed)->toBeFalse()
            ->and($res['extension-contract']->passed)->toBeFalse()
            ->and($res['schema-gate']->passed)->toBeFalse()
            ->and($res['package-docs']->passed)->toBeFalse()
            ->and($res['naming']->passed)->toBeFalse()
            ->and($res['quality-ceiling']->passed)->toBeFalse();
    } finally {
        $ws->cleanup();
    }
});

it('falls back to bundled schemas, honors package owns, and supports multi-root psr-4 in downstream repos', function (): void {
    $ws = new TempWorkspace('packman-downstream-gates-');

    try {
        $pkg = $ws->seedCleanMonorepoPackage('acme/custom', 'packages/custom', 'Acme\\Custom\\', 'laravel-package', 'L2');
        $pkg['owns'] = ['CustomDomain'];
        $ws->writeJson('packages/custom/composer.json', [
            'name' => 'acme/custom',
            'description' => 'Custom package.',
            'type' => 'library',
            'require' => ['php' => '^8.4'],
            'autoload' => [
                'psr-4' => [
                    'Acme\\Custom\\' => 'src/',
                    'Acme\\PublicExceptions\\' => 'src/Exceptions/Public/',
                ],
            ],
        ]);
        $ws->write('packages/custom/src/CustomDomain/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Custom\CustomDomain;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class Service {}

PHP);
        $ws->write('packages/custom/src/Exceptions/Public/DomainFailure.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\PublicExceptions;

use Packman\Attributes\Api;
use RuntimeException;

#[Api(since: '1.0')]
final class DomainFailure extends RuntimeException {}

PHP);
        $ws->writePackmanJson([$pkg]);

        (new ApiManifestGenerator)->sync($ws->root, [
            ['name' => $pkg['name'], 'path' => $pkg['path']],
        ], write: true);

        $results = GateRunner::default()->run($ws->root);
        expect($results)->toHaveCount(16);

        foreach ($results as $result) {
            expect($result->passed)->toBeTrue("Gate [{$result->gateId}] failed: ".implode('; ', $result->violations));
        }
    } finally {
        $ws->cleanup();
    }
});
