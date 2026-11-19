<?php

declare(strict_types=1);

use Packman\DevTools\Console\ApplicationFactory;
use Packman\DevTools\Data\Surface\SurfaceReport;
use Packman\DevTools\Scanners\Surface\PhpApiDocument;
use Packman\DevTools\Scanners\Surface\SurfaceScanner;
use Packman\DevTools\Tests\Support\TempWorkspace;
use Symfony\Component\Console\Tester\CommandTester;

it('scans #[Api], #[Spi], and #[Internal] surfaces and detects attribute conflicts and leaks', function (): void {
    $ws = new TempWorkspace('packman-surface-test-');

    try {
        $ws->seedSchemasFromRepo(dirname(__DIR__, 4));
        $ws->write('packages/sample/src/Contracts/PublicGateway.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Sample\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PublicGateway
{
    public function send(string $payload): void;
}

PHP);
        $ws->write('packages/sample/src/Support/ExplicitInternalHelper.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Sample\Support;

use Packman\Attributes\Internal;

#[Internal]
final class ExplicitInternalHelper {}

PHP);

        $scanner = new SurfaceScanner;
        $clean = $scanner->scanPackage('acme/sample', $ws->path('packages/sample/src'));
        expect($clean->ok())->toBeTrue()
            ->and(array_keys($clean->symbols))->toBe(['Acme\\Sample\\Contracts\\PublicGateway'])
            ->and($clean->symbols['Acme\\Sample\\Contracts\\PublicGateway']['spi_kind'])->toBe('implement');

        // Negative: #[Internal] + #[Api] conflict AND public method leaking ExplicitInternalHelper
        $ws->write('packages/sample/src/Data/ConflictingType.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Sample\Data;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;

#[Internal]
#[Api(since: '1.0')]
final class ConflictingType {}

PHP);
        $ws->write('packages/sample/src/Data/LeakyApi.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Sample\Data;

use Acme\Sample\Support\ExplicitInternalHelper;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class LeakyApi
{
    public function expose(): ExplicitInternalHelper
    {
        return new ExplicitInternalHelper;
    }
}

PHP);

        $ws->write('packages/sample/src/Data/DocInternalWithoutAttr.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Sample\Data;

/** @internal */
final class DocInternalWithoutAttr {}

PHP);

        $bad = $scanner->scanPackage('acme/sample', $ws->path('packages/sample/src'));
        expect($bad->ok())->toBeFalse()
            ->and(implode("\n", array_map(static fn ($d): string => $d->code, $bad->diagnostics)))
            ->toContain('surface.internal_conflict')
            ->toContain('surface.internal_leak')
            ->toContain('surface.missing_attribute');
    } finally {
        $ws->cleanup();
    }
});

it('encodes an empty public surface as a schema-valid symbol map', function (): void {
    $report = new SurfaceReport('acme/sample', [], []);
    $json = PhpApiDocument::encode($report);

    expect(PhpApiDocument::validateJsonAgainstSchema($json, dirname(__DIR__, 4).'/schemas/php-api.schema.json'))->toBe([]);
});

it('runs php-api --write and --check detecting drift cleanly via CLI', function (): void {
    $ws = new TempWorkspace('packman-phpapi-cli-');

    try {
        $ws->seedSchemasFromRepo(dirname(__DIR__, 4));
        $pkg = $ws->seedCleanMonorepoPackage('acme/alpha', 'packages/alpha', 'Acme\\Alpha\\');
        $ws->writePackmanJson([$pkg]);

        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('php-api'));

        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(1);
        expect($tester->execute(['--root' => $ws->root, '--write' => true]))->toBe(0);
        expect(is_file($ws->path('packages/alpha/resources/contracts/php-api.json')))->toBeTrue();
        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(0);

        // Mutate API file -> --check detects drift
        $ws->write('packages/alpha/src/Data/AddedDto.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Alpha\Data;

use Packman\Attributes\Api;

#[Api(since: '1.1')]
final readonly class AddedDto
{
    public function __construct(public string $id) {}
}

PHP);
        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(1);
    } finally {
        $ws->cleanup();
    }
});
