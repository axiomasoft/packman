<?php

declare(strict_types=1);

use Packman\DevTools\Console\ApplicationFactory;
use Packman\DevTools\Gates\Contracts\SchemaGate;
use Packman\DevTools\Gates\Runner\GateRunner;
use Packman\DevTools\Generators\Config\ConfigSchemaGenerator;
use Packman\DevTools\Internal\Schemas\JsonSchemaValidator;
use Packman\DevTools\Scanners\Errors\ErrorSourceScanner;
use Packman\DevTools\Tests\Support\TempWorkspace;
use Symfony\Component\Console\Tester\CommandTester;

it('fails when a package declares ErrorCode cases without a ProblemDefinitionProvider', function (): void {
    $ws = new TempWorkspace('packman-errors-missing-provider-');

    try {
        $ws->seedSchemasFromRepo(dirname(__DIR__, 4));
        $pkg = $ws->seedCleanMonorepoPackage('acme/alpha', 'packages/alpha', 'Acme\\Alpha\\');
        $ws->writePackmanJson([$pkg]);

        $ws->write('packages/alpha/src/Enums/AlphaErrorCode.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Alpha\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

#[Api(since: '1.0')]
enum AlphaErrorCode: string implements ErrorCode
{
    case MissingItem = 'alpha.missing_item';

    public function value(): string
    {
        return $this->value;
    }
}

PHP);

        $report = (new ErrorSourceScanner)->scanPackages($ws->root, [
            ['name' => 'acme/alpha', 'path' => 'packages/alpha'],
        ]);

        expect($report->ok())->toBeFalse()
            ->and(implode("\n", $report->diagnostics))->toContain('missing a ProblemDefinition in package providers');

        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('error-codes'));
        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(1)
            ->and($tester->execute(['--root' => $ws->root, '--write' => true]))->toBe(1);
    } finally {
        $ws->cleanup();
    }
});

it('scans ErrorCode enums, validates uniqueness and URNs, and syncs error-codes manifests', function (): void {
    $ws = new TempWorkspace('packman-errors-test-');
    $autoloader = static function (string $class) use ($ws): void {
        $prefixes = [
            'Acme\\Alpha\\' => $ws->path('packages/alpha/src/'),
            'Acme\\Beta\\' => $ws->path('packages/beta/src/'),
        ];

        foreach ($prefixes as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $rel = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                $file = $dir.$rel;

                if (is_file($file)) {
                    require_once $file;
                }
            }
        }
    };
    spl_autoload_register($autoloader);

    try {
        $ws->seedSchemasFromRepo(dirname(__DIR__, 4));
        $pkgA = $ws->seedCleanMonorepoPackage('acme/alpha', 'packages/alpha', 'Acme\\Alpha\\');
        $pkgB = $ws->seedCleanMonorepoPackage('acme/beta', 'packages/beta', 'Acme\\Beta\\');
        $ws->writePackmanJson([$pkgA, $pkgB]);

        $ws->write('packages/alpha/src/Enums/AlphaErrorCode.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Alpha\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

#[Api(since: '1.0')]
enum AlphaErrorCode: string implements ErrorCode
{
    case MissingItem = 'alpha.missing_item';

    public function value(): string
    {
        return $this->value;
    }
}

PHP);
        $ws->write('packages/alpha/src/Internal/Errors/AlphaProblemDefinitions.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Alpha\Internal\Errors;

use Acme\Alpha\Enums\AlphaErrorCode;
use Packman\Attributes\Internal;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

#[Internal]
final readonly class AlphaProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(private string $urnPrefix = 'urn:packman:problem:') {}

    public function definitions(): iterable
    {
        yield new ProblemDefinition(
            code: AlphaErrorCode::MissingItem,
            type: $this->urnPrefix.'alpha.missing_item',
            defaultStatus: 404,
            title: 'Missing item.',
            retryable: false,
            category: ErrorCategory::NotFound,
        );
    }
}

PHP);

        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('error-codes'));

        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(1);
        expect($tester->execute(['--root' => $ws->root, '--write' => true]))->toBe(0);
        expect(is_file($ws->path('packages/alpha/resources/contracts/error-codes.json')))->toBeTrue();
        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(0);

        // Negative: duplicate wire code in beta
        $ws->write('packages/beta/src/Enums/BetaErrorCode.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Beta\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

#[Api(since: '1.0')]
enum BetaErrorCode: string implements ErrorCode
{
    case DuplicateItem = 'alpha.missing_item';

    public function value(): string
    {
        return $this->value;
    }
}

PHP);

        $report = (new ErrorSourceScanner)->scanPackages($ws->root, [
            ['name' => 'acme/alpha', 'path' => 'packages/alpha'],
            ['name' => 'acme/beta', 'path' => 'packages/beta'],
        ]);
        expect($report->ok())->toBeFalse()
            ->and(implode("\n", $report->diagnostics))->toContain('Duplicate error wire code');
    } finally {
        spl_autoload_unregister($autoloader);
        $ws->cleanup();
    }
});

it('generates JSON configuration schemas from package config files and ConfigSection classes', function (): void {
    $ws = new TempWorkspace('packman-config-schema-');

    try {
        $ws->seedSchemasFromRepo(dirname(__DIR__, 4));
        $pkg = $ws->seedCleanMonorepoPackage('acme/cfg', 'packages/cfg', 'Acme\\Cfg\\');
        $ws->write('packages/cfg/config/cfg.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'retries' => env('CFG_RETRIES', 3),
    'mode' => 'strict',
    /** @var ?string */
    'delivery_class' => 'standard',
    /** @var int|null */
    'payment_due_minutes' => 4320,
    'flag' => (bool) env('CFG_FLAG', false),
    'optional_conn' => env('CFG_CONN'),
    'dynamic' => strtoupper('abc'),
];

PHP);
        $ws->writePackmanJson([$pkg]);

        $schema = (new ConfigSchemaGenerator)->generateForPackage('acme/cfg', $ws->path('packages/cfg'));
        expect($schema)->not->toBeNull()
            ->and($schema['properties']['cfg']['properties']['enabled']['type'] ?? null)->toBe('boolean')
            ->and($schema['properties']['cfg']['properties']['delivery_class']['type'] ?? null)->toBe(['string', 'null'])
            ->and($schema['properties']['cfg']['properties']['payment_due_minutes']['type'] ?? null)->toBe(['integer', 'null'])
            ->and($schema['properties']['cfg']['properties']['retries']['default'] ?? null)->toBe(3)
            ->and($schema['properties']['cfg']['properties']['flag']['type'] ?? null)->toBe('boolean')
            ->and(array_key_exists('default', $schema['properties']['cfg']['properties']['optional_conn']))->toBeTrue()
            ->and($schema['properties']['cfg']['properties']['optional_conn']['default'])->toBeNull()
            ->and($schema['properties']['cfg']['properties']['dynamic'])->not->toBe([]);

        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('config-schema'));

        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(1);
        expect($tester->execute(['--root' => $ws->root, '--write' => true]))->toBe(0);
        expect(is_file($ws->path('packages/cfg/resources/schemas/config.schema.json')))->toBeTrue();
        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(0);

        $schemaPath = $ws->path('packages/cfg/resources/schemas/config.schema.json');
        $validErrors = JsonSchemaValidator::validateJsonAgainstFile(
            json_encode([
                'cfg' => [
                    'enabled' => true,
                    'retries' => 3,
                    'mode' => 'strict',
                    'delivery_class' => null,
                    'payment_due_minutes' => null,
                    'flag' => false,
                    'optional_conn' => null,
                    'dynamic' => 'ABC',
                ],
            ], JSON_THROW_ON_ERROR),
            $schemaPath,
        );
        expect($validErrors)->toBe([]);

        // Negative: SchemaGate rejects a JSON schema containing invalid [] subschema
        $ws->write('packages/cfg/resources/schemas/broken.schema.json', "{\n  \"properties\": {\n    \"bad\": []\n  }\n}\n");
        $gateResult = GateRunner::default()->run($ws->root, [(new SchemaGate)->id()]);
        expect($gateResult[0]->passed)->toBeFalse();
        unlink($ws->path('packages/cfg/resources/schemas/broken.schema.json'));

        // Negative: SchemaGate eagerly rejects invalid root keyword {"type":"bogus"}
        $ws->write('packages/cfg/resources/schemas/bogus-type.schema.json', "{\"type\":\"bogus\"}\n");
        $bogusTypeResult = GateRunner::default()->run($ws->root, [(new SchemaGate)->id()]);
        expect($bogusTypeResult[0]->passed)->toBeFalse()
            ->and(implode("\n", $bogusTypeResult[0]->violations))->toContain('bogus');
        unlink($ws->path('packages/cfg/resources/schemas/bogus-type.schema.json'));

        // Negative: SchemaGate eagerly rejects invalid nested keyword {"properties":{"a":{"minimum":"bad"}}}
        $ws->write('packages/cfg/resources/schemas/bad-minimum.schema.json', "{\"properties\":{\"a\":{\"minimum\":\"bad\"}}}\n");
        $badMinimumResult = GateRunner::default()->run($ws->root, [(new SchemaGate)->id()]);
        expect($badMinimumResult[0]->passed)->toBeFalse()
            ->and(implode("\n", $badMinimumResult[0]->violations))->toContain('minimum');
        unlink($ws->path('packages/cfg/resources/schemas/bad-minimum.schema.json'));

        // Negative: SchemaGate rejects nested [] subschema inside $defs
        $ws->write('packages/cfg/resources/schemas/nested-empty.schema.json', "{\"\$defs\":{\"x\":{\"properties\":{\"bad\":[]}}}}\n");
        $nestedEmptyResult = GateRunner::default()->run($ws->root, [(new SchemaGate)->id()]);
        expect($nestedEmptyResult[0]->passed)->toBeFalse();
    } finally {
        $ws->cleanup();
    }
});

it('validates representative configuration payloads against all generated monorepo config.schema.json files via Opis JsonSchema', function (): void {
    $repoRoot = dirname(__DIR__, 4);

    $cases = [
        'core default' => [
            'packages/core/resources/schemas/config.schema.json',
            ['packman' => ['unsafe' => ['allow_in_production' => false], 'errors' => ['urn_prefix' => 'urn:packman:problem:'], 'acknowledged_dangerous_modes' => []]],
            true,
        ],
        'modules custom' => [
            'packages/modules/resources/schemas/config.schema.json',
            ['modules' => [
                'connection' => 'pgsql',
                'cache' => ['store' => 'redis', 'ttl' => 600, 'prefix' => 'mod'],
                'package_types' => ['packman-module'],
                'extra_keys' => [['packman', 'module']],
                'cache_path' => 'bootstrap/cache/modules.php',
                'lock_key' => 'modules:lifecycle-graph',
                'compiler_package' => 'axiomasoft/packman-modules',
                'tables' => ['modules' => 'mod_modules', 'records' => 'mod_records', 'lifecycle_log' => 'mod_lifecycle_log'],
                'registry_schema' => 'packman-registry/1',
                'compile_command' => 'modules:compile',
            ]],
            true,
        ],
        'modules nullable env' => [
            'packages/modules/resources/schemas/config.schema.json',
            ['modules' => ['connection' => null, 'cache' => ['store' => null, 'ttl' => 3600, 'prefix' => 'modules']]],
            true,
        ],
        'plugins empty array' => [
            'packages/plugins/resources/schemas/config.schema.json',
            ['packman-plugins' => []],
            true,
        ],
        'plugins map' => [
            'packages/plugins/resources/schemas/config.schema.json',
            ['packman-plugins' => ['disabled' => ['acme.demo']]],
            true,
        ],
        'schema defaults' => [
            'packages/schema/resources/schemas/config.schema.json',
            ['packman-schema' => ['connection' => null, 'table_prefix' => 'pmn_', 'module_prerequisite_set' => 'axiomasoft/packman-modules:tenant']],
            true,
        ],
        'panels defaults' => [
            'packages/panels/resources/schemas/config.schema.json',
            ['packman-panels' => [
                'provider_tag' => 'packman.panels.providers',
                'views' => ['connection' => null, 'table' => 'sys_saved_views', 'scopes_table' => 'sys_saved_view_scopes'],
                'defaults' => [
                    'resource_prefix' => true,
                    'cache' => ['store' => null, 'ttl' => 300, 'generation' => 1],
                    'consistency' => ['reads' => 'primary', 'state_refresh' => 'request'],
                    'trace_decisions' => false,
                ],
            ]],
            true,
        ],
        'filament empty resources' => [
            'packages/filament/resources/schemas/config.schema.json',
            ['packman-filament' => ['resources' => []]],
            true,
        ],
        'filament resource slug disabled' => [
            'packages/filament/resources/schemas/config.schema.json',
            ['packman-filament' => ['resources' => ['orders' => false]]],
            true,
        ],
        'filament resource FQCN enabled' => [
            'packages/filament/resources/schemas/config.schema.json',
            ['packman-filament' => ['resources' => ['App\\Filament\\Resources\\OrderResource' => true]]],
            true,
        ],
        'filament invalid resource value' => [
            'packages/filament/resources/schemas/config.schema.json',
            ['packman-filament' => ['resources' => ['orders' => 'invalid']]],
            false,
        ],
        'testkit sections' => [
            'packages/testkit/resources/schemas/config.schema.json',
            ['sections' => ['report_test.channel' => 'strict', 'report_test.enabled' => true]],
            true,
        ],
    ];

    foreach ($cases as $label => [$relSchema, $payload, $shouldPass]) {
        $errors = JsonSchemaValidator::validateJsonAgainstFile(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $repoRoot.'/'.$relSchema,
        );

        if ($shouldPass) {
            expect($errors)->toBe([], "Expected valid payload for [{$label}], got: ".implode('; ', $errors));
        } else {
            expect($errors)->not->toBe([], "Expected invalid payload for [{$label}] to fail validation.");
        }
    }
});
