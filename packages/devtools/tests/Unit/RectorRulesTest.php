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
        $_SERVER['PACKMAN_WRITE_TEST'] = 'ok';
        foreach ($_SERVER['counters'] as &$counter) {
            $counter++;
        }
        unset($counter);
        $host = $_SERVER['HTTP_HOST'];
        $hasHost = isset($_SERVER['HTTP_HOST']);
        $argv = $_SERVER['argv'];
        $apiClass = 'Packman\Attributes\Api';
        $optionalExists = class_exists('Packman\Modules\Testing\ExtensionContract');
        $moduleRef = 'Packman\Modules\Data\Lifecycle\ModuleManifest';

        return [$host, $hasHost, $argv, $apiClass, $optionalExists, $moduleRef];
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
use Rector\Config\RectorConfig;

require_once '{$autoload}';
require_once '{$jobFixture}';

return static function (RectorConfig \$rectorConfig): void {
    \$rectorConfig->paths(['{$ws->path('app')}', '{$ws->path('packages')}', '{$ws->path('tests')}']);
    PackmanRectorSet::configure(\$rectorConfig);
    \$rectorConfig->ruleWithConfiguration(BoundarySafeStringClassRector::class, [
        BoundarySafeStringClassRector::PRESERVED_PREFIXES_KEY => ['Packman\\\\Modules\\\\'],
    ]);
};

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
            ->toContain("\$_SERVER['PACKMAN_WRITE_TEST'] = 'ok'")
            ->toContain("foreach (\$_SERVER['counters'] as &\$counter)")
            ->toContain("isset(\$_SERVER['HTTP_HOST'])")
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

it('marks foreach by-reference superglobal iterables in write context so Rector preserves them', function (): void {
    $ws = new TempWorkspace('packman-rector-foreach-ref-');

    try {
        $targetFile = $ws->write('packages/demo/src/CounterMutator.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Demo;

final class CounterMutator
{
    public function mutate(): void
    {
        foreach ($_SERVER['counters'] as &$counter) {
            $counter++;
        }
        unset($counter);

        foreach ($_SERVER['readonly_items'] as $item) {
            $_ = $item;
        }
    }
}
PHP);

        $repoRoot = dirname(__DIR__, 4);
        $rectorBin = $repoRoot.'/vendor/bin/rector';
        $autoloadFile = $repoRoot.'/vendor/autoload.php';

        $configFile = $ws->write('rector.php', <<<'PHP'
<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([__DIR__.'/packages/demo/src']);
    PackmanRectorSet::configure($rectorConfig);
};
PHP);

        $process = new Process([
            PHP_BINARY,
            $rectorBin,
            'process',
            '--config='.$configFile,
            '--autoload-file='.$autoloadFile,
            '--no-progress-bar',
        ], $ws->root);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput()."\n".$process->getOutput());

        $updated = (string) file_get_contents($targetFile);
        expect($updated)
            ->toContain("foreach (\$_SERVER['counters'] as &\$counter)")
            ->toContain("Request::server('readonly_items')");
    } finally {
        $ws->cleanup();
    }
});
