<?php

declare(strict_types=1);

use Packman\DevTools\Console\ApplicationFactory;
use Packman\DevTools\Generators\Config\ConfigSchemaGenerator;
use Packman\DevTools\Scanners\Errors\ErrorSourceScanner;
use Packman\DevTools\Tests\Support\TempWorkspace;
use Symfony\Component\Console\Tester\CommandTester;

it('scans ErrorCode enums, validates uniqueness and URNs, and syncs error-codes manifests', function (): void {
    $ws = new TempWorkspace('packman-errors-test-');

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
];

PHP);
        $ws->writePackmanJson([$pkg]);

        $schema = (new ConfigSchemaGenerator)->generateForPackage('acme/cfg', $ws->path('packages/cfg'));
        expect($schema)->not->toBeNull()
            ->and($schema['properties']['cfg']['properties']['enabled']['type'] ?? null)->toBe('boolean')
            ->and($schema['properties']['cfg']['properties']['retries']['default'] ?? null)->toBe(3);

        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('config-schema'));

        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(1);
        expect($tester->execute(['--root' => $ws->root, '--write' => true]))->toBe(0);
        expect(is_file($ws->path('packages/cfg/resources/schemas/config.schema.json')))->toBeTrue();
        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(0);
    } finally {
        $ws->cleanup();
    }
});
