<?php

declare(strict_types=1);

use Packman\DevTools\Console\ApplicationFactory;
use Packman\DevTools\Gates\Runner\GateRunner;
use Packman\DevTools\Split\CompatibilityMatrixChecker;
use Packman\DevTools\Split\ReleasePreflightChecker;
use Packman\DevTools\Sync\EcosystemAiSynchronizer;
use Packman\DevTools\Tests\Support\TempWorkspace;
use Symfony\Component\Console\Tester\CommandTester;

it('handles missing ai/ source directory gracefully in dry-run and live sync', function (): void {
    $ws = new TempWorkspace('packman-aisync-empty-');

    try {
        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('ai:sync'));

        $exit = $tester->execute(['--root' => $ws->root, '--dry-run' => true]);
        expect($exit)->toBe(0)
            ->and($tester->getDisplay())->toContain('No canonical ai/ directory found');
    } finally {
        $ws->cleanup();
    }
});

it('falls back to bundled canonical ai pack when packman.json exists without a local ai directory', function (): void {
    $ws = new TempWorkspace('packman-aisync-downstream-');

    try {
        $ws->writeJson('packman.json', [
            'schema_version' => 1,
            'name' => 'acme/downstream-monorepo',
            'profile' => 'laravel-package',
            'packages' => [],
        ]);

        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('ai:sync'));

        expect($tester->execute(['--root' => $ws->root, '--dry-run' => true]))->toBe(1)
            ->and($tester->getDisplay())->toContain('MISSING AGENTS.md');
        expect($tester->execute(['--root' => $ws->root]))->toBe(0);
        expect($tester->execute(['--root' => $ws->root, '--dry-run' => true]))->toBe(0);
        expect(is_file($ws->path('.codex/agents/packman-reviewer.toml')))->toBeTrue();
    } finally {
        $ws->cleanup();
    }
});

it('synchronizes canonical ai/ pack into .claude, valid Codex v0.169+ TOML, .gemini, and AGENTS.md while preserving reviewer overrides', function (): void {
    $ws = new TempWorkspace('packman-aisync-fixture-');

    try {
        $fixtureDir = __DIR__.'/../Fixtures/AiPack';
        $ws->write('ai/manifest.json', (string) file_get_contents($fixtureDir.'/manifest.json'));
        $ws->write('ai/rules/clean-architecture.md', (string) file_get_contents($fixtureDir.'/rules/clean-architecture.md'));
        $ws->write('ai/agents/code-reviewer.md', (string) file_get_contents($fixtureDir.'/agents/code-reviewer.md'));

        $overrideContent = "# Local Reviewer Overrides\nKeep strict boundary checks enabled.\n";
        $ws->write('.claude/agents/code-reviewer-reviewer-overrides.md', $overrideContent);

        $dryReport = (new EcosystemAiSynchronizer)->sync($ws->root, dryRun: true);
        expect($dryReport['ok'])->toBeFalse()
            ->and($dryReport['status'])->toBe('dry_run')
            ->and($dryReport['written'])->toBe([])
            ->and($dryReport['planned'])->toContain('.codex/agents/code-reviewer.toml')
            ->and($dryReport['missing'])->toContain('.codex/agents/code-reviewer.toml')
            ->and($dryReport['preserved'])->toContain('.claude/agents/code-reviewer-reviewer-overrides.md');

        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('ai:sync'));
        expect($tester->execute(['--root' => $ws->root, '--dry-run' => true]))->toBe(1)
            ->and($tester->getDisplay())->toContain('.codex/agents/code-reviewer.toml')
            ->and($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(1);

        expect($tester->execute(['--root' => $ws->root]))->toBe(0);
        expect($tester->execute(['--root' => $ws->root, '--dry-run' => true]))->toBe(0)
            ->and($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(0);

        $toml = (string) file_get_contents($ws->path('.codex/agents/code-reviewer.toml'));
        expect($toml)
            ->toContain('name = "code-reviewer"')
            ->toContain("[skills]\nenabled = [\"clean-php\", \"packman-gates\"]")
            ->not->toContain('skills = [')
            ->not->toContain('command_requirements');

        expect(is_file($ws->path('.claude/rules/clean-architecture.md')))->toBeTrue()
            ->and(is_file($ws->path('.gemini/rules/clean-architecture.md')))->toBeTrue()
            ->and(is_file($ws->path('.claude/agent-pack.lock.json')))->toBeTrue()
            ->and((string) file_get_contents($ws->path('AGENTS.md')))->toContain('code-reviewer')
            ->and((string) file_get_contents($ws->path('.claude/agents/code-reviewer-reviewer-overrides.md')))->toBe($overrideContent);

        // Missing generated artifact must fail --dry-run / --check
        unlink($ws->path('.codex/agents/code-reviewer.toml'));
        expect($tester->execute(['--root' => $ws->root, '--dry-run' => true]))->toBe(1)
            ->and($tester->getDisplay())->toContain('MISSING .codex/agents/code-reviewer.toml');

        // Re-sync then mutate AGENTS.md -> drifted artifact must fail --check / --dry-run
        expect($tester->execute(['--root' => $ws->root]))->toBe(0);
        $ws->write('AGENTS.md', "# Drifted\n");
        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(1)
            ->and($tester->getDisplay())->toContain('DRIFT AGENTS.md');

        // Re-sync then plant stale orphan artifacts across managed output directories -> --dry-run/--check fails, live ai:sync prunes them while preserving reviewer overrides
        expect($tester->execute(['--root' => $ws->root]))->toBe(0);
        $ws->write('.claude/rules/obsolete.md', "# Stale rule\n");
        $ws->write('.gemini/rules/obsolete.md', "# Stale gemini rule\n");
        $ws->write('.claude/agents/stale-agent.md', "# Stale claude agent\n");
        $ws->write('.codex/agents/stale-agent.toml', "name = \"stale-agent\"\n");

        $orphanDryReport = (new EcosystemAiSynchronizer)->sync($ws->root, dryRun: true);
        expect($orphanDryReport['ok'])->toBeFalse()
            ->and($orphanDryReport['orphans'])->toBe([
                '.claude/agents/stale-agent.md',
                '.claude/rules/obsolete.md',
                '.codex/agents/stale-agent.toml',
                '.gemini/rules/obsolete.md',
            ])
            ->and($orphanDryReport['preserved'])->toContain('.claude/agents/code-reviewer-reviewer-overrides.md');

        expect($tester->execute(['--root' => $ws->root, '--dry-run' => true]))->toBe(1)
            ->and($tester->getDisplay())
            ->toContain('ORPHAN .claude/rules/obsolete.md')
            ->toContain('ORPHAN .gemini/rules/obsolete.md');
        expect($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(1);

        expect($tester->execute(['--root' => $ws->root]))->toBe(0)
            ->and($tester->getDisplay())->toContain('PRUNE .claude/rules/obsolete.md');

        expect(is_file($ws->path('.claude/rules/obsolete.md')))->toBeFalse()
            ->and(is_file($ws->path('.gemini/rules/obsolete.md')))->toBeFalse()
            ->and(is_file($ws->path('.claude/agents/stale-agent.md')))->toBeFalse()
            ->and(is_file($ws->path('.codex/agents/stale-agent.toml')))->toBeFalse()
            ->and((string) file_get_contents($ws->path('.claude/agents/code-reviewer-reviewer-overrides.md')))->toBe($overrideContent);

        expect($tester->execute(['--root' => $ws->root, '--dry-run' => true]))->toBe(0)
            ->and($tester->execute(['--root' => $ws->root, '--check' => true]))->toBe(0);
    } finally {
        $ws->cleanup();
    }
});

it('plans subtree splits cleanly via SplitCommand, detects path repository leaks, and checks compatibility matrix', function (): void {
    $ws = new TempWorkspace('packman-split-cli-');

    try {
        $ws->seedSchemasFromRepo(dirname(__DIR__, 4));
        $pkgA = $ws->seedCleanMonorepoPackage('acme/core', 'packages/core', 'Acme\\Core\\', 'php-library', 'L1');
        $ws->writePackmanJson([$pkgA]);
        $ws->writeJson('compatibility.json', ['php' => '^8.4']);
        $ws->writeJson('split.json', [
            'default_branch' => 'main',
            'packages' => [
                ['name' => 'acme/core', 'path' => 'packages/core', 'repository' => 'git@github.com:acme/core.git'],
            ],
        ]);

        expect((new CompatibilityMatrixChecker)->check($ws->root)['ok'])->toBeTrue();

        $app = ApplicationFactory::create($ws->root);
        $tester = new CommandTester($app->find('split'));
        $exit = $tester->execute([
            '--root' => $ws->root,
            '--dry-run' => true,
            '--only' => ['core'],
        ]);
        expect($exit)->toBe(0)
            ->and($tester->getDisplay())->toContain('git subtree split --prefix=packages/core');

        // Preflight fails cleanly if php-api.json is not generated yet
        $preflight = (new ReleasePreflightChecker(GateRunner::default()))->verify($ws->root);
        expect($preflight['ok'])->toBeFalse();

        // Negative split: embed disallowed path repository in composer.json
        $ws->writeJson('packages/core/composer.json', [
            'name' => 'acme/core',
            'description' => 'Core.',
            'type' => 'library',
            'require' => ['php' => '^8.4'],
            'repositories' => [['type' => 'path', 'url' => '../other']],
        ]);
        expect($tester->execute(['--root' => $ws->root, '--dry-run' => true]))->toBe(1);
    } finally {
        $ws->cleanup();
    }
});
