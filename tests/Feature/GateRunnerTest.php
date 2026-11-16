<?php

declare(strict_types=1);

use Packman\DevTools\Console\ApplicationFactory;
use Packman\DevTools\Gates\Runner\GateRunner;
use Packman\DevTools\Tests\Support\TempWorkspace;
use Symfony\Component\Console\Tester\CommandTester;

it('registers all 16 quality gates and runs GateRunnerCommand CLI cleanly', function (): void {
    $runner = GateRunner::default();
    expect($runner->gates())->toHaveCount(16);

    $ws = new TempWorkspace;

    try {
        $ws->seedSchemasFromRepo(dirname(__DIR__, 4));
        $pkg = $ws->seedCleanMonorepoPackage('acme/clean', 'packages/clean', 'Acme\\Clean\\', 'php-library', 'L1');
        $ws->writePackmanJson([$pkg]);

        $app = ApplicationFactory::create($ws->root);
        $command = $app->find('gate');
        $tester = new CommandTester($command);

        $exit = $tester->execute([
            '--root' => $ws->root,
            '--only' => ['naming,quality-ceiling', 'package-docs'],
            '--json' => true,
        ]);

        expect($exit)->toBe(0);
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        expect($decoded['ok'])->toBeTrue()
            ->and(array_column($decoded['gates'], 'id'))->toBe(['package-docs', 'naming', 'quality-ceiling']);

        $badExit = $tester->execute([
            '--root' => $ws->root,
            '--only' => ['nonexistent-gate'],
        ]);
        expect($badExit)->toBe(1);
    } finally {
        $ws->cleanup();
    }
});
