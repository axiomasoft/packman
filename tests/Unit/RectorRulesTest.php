<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Packman\DevTools\Rector\Rules\Boundaries\BoundarySafeStringClassRector;
use Packman\DevTools\Rector\Rules\Laravel\ServerVariableToRequestFacadeRector;
use Packman\DevTools\Tests\Support\TempWorkspace;
use Symfony\Component\Process\Process;

it('transforms and preserves files cleanly across PackmanRectorSet and all 3 custom rules', function (): void {
    $ws = new TempWorkspace('packman-rector-test-');
    $repoRoot = dirname(__DIR__, 4);

    try {
        // 1) Domain HTTP controller file -> rewritten by ServerVariableToRequestFacadeRector & BoundarySafeStringClassRector
        $domainFile = $ws->write('app/Http/Controllers/SampleController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class SampleController
{
    public function handle(): array
    {
        $host = $_SERVER['HTTP_HOST'];
        $argv = $_SERVER['argv'];
        $apiClass = 'Packman\Attributes\Api';
        $optionalExists = class_exists('Packman\Modules\Testing\ExtensionContract');
        $moduleRef = 'Packman\Modules\Data\Lifecycle\ModuleManifest';

        return [$host, $argv, $apiClass, $optionalExists, $moduleRef];
    }
}

PHP);

        // 2) Preserved testkit path -> $_SERVER preserved untouched
        $preservedFile = $ws->write('packages/testkit/src/EnvProbe.php', <<<'PHP'
<?php

declare(strict_types=1);

final class EnvProbe
{
    public function host(): mixed
    {
        return $_SERVER['HTTP_HOST'] ?? null;
    }
}

PHP);

        // 3) Job static dispatch rewrite vs tests path preservation
        $jobFixture = $ws->write('app/Jobs/SendWelcomeJob.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;

final class SendWelcomeJob
{
    use Dispatchable;

    public function __construct(public string $userId) {}
}

PHP);
        $callerFile = $ws->write('app/Services/Caller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendWelcomeJob;

final class Caller
{
    public function run(): void
    {
        SendWelcomeJob::dispatch('u-1');
    }
}

PHP);
        $testCallerFile = $ws->write('tests/Unit/CallerTest.php', <<<'PHP'
<?php

declare(strict_types=1);

use App\Jobs\SendWelcomeJob;

SendWelcomeJob::dispatch('u-test');

PHP);

        $autoload = $repoRoot.'/vendor/autoload.php';
        $rectorConfig = $ws->write('rector-test-config.php', <<<PHP
<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Packman\DevTools\Rector\Rules\Boundaries\BoundarySafeStringClassRector;
use Packman\DevTools\Rector\Rules\Laravel\DispatchToHelperFunctionsRector;
use Packman\DevTools\Rector\Rules\Laravel\ServerVariableToRequestFacadeRector;
use Rector\Config\RectorConfig;

require_once '{$autoload}';
require_once '{$jobFixture}';

return RectorConfig::configure()
    ->withPaths(['{$ws->path('app')}', '{$ws->path('packages')}', '{$ws->path('tests')}'])
    ->withRules([
        ServerVariableToRequestFacadeRector::class,
        DispatchToHelperFunctionsRector::class,
    ])
    ->withConfiguredRule(BoundarySafeStringClassRector::class, [
        BoundarySafeStringClassRector::PRESERVED_PREFIXES_KEY => ['Packman\\\\Modules\\\\'],
    ]);

PHP);

        // Also assert PackmanRectorSet::configure exists cleanly via reflection/signature
        expect(method_exists(PackmanRectorSet::class, 'configure'))->toBeTrue();

        $process = new Process(
            [PHP_BINARY, $repoRoot.'/vendor/bin/rector', 'process', '--config='.$rectorConfig, '--no-progress-bar'],
            $repoRoot,
        );
        $process->setTimeout(60);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput()."\n".$process->getOutput());

        $updatedController = (string) file_get_contents($domainFile);
        expect($updatedController)
            ->toContain('Request::server(\'HTTP_HOST\')')
            ->toContain('$_SERVER[\'argv\']')
            ->toContain('Api::class')
            ->toContain("class_exists('Packman\\Modules\\Testing\\ExtensionContract')")
            ->toContain("'Packman\\Modules\\Data\\Lifecycle\\ModuleManifest'");

        $preservedContent = (string) file_get_contents($preservedFile);
        expect($preservedContent)->toContain('$_SERVER[\'HTTP_HOST\']');

        $updatedCaller = (string) file_get_contents($callerFile);
        expect($updatedCaller)->toContain('dispatch(new')
            ->and($updatedCaller)->not->toContain('SendWelcomeJob::dispatch(');

        $preservedTestCaller = (string) file_get_contents($testCallerFile);
        expect($preservedTestCaller)->toContain("SendWelcomeJob::dispatch('u-test')");
    } finally {
        $ws->cleanup();
    }
});
