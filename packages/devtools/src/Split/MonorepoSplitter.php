<?php

declare(strict_types=1);

namespace Packman\DevTools\Split;

use Packman\Attributes\Api;
use Symfony\Component\Process\Process;

/**
 * Plans and optionally executes deterministic git subtree splits configured in split.json.
 */
#[Api(since: '1.0')]
final class MonorepoSplitter
{
    /**
     * @param  list<string>  $only
     * @return array{ok: bool, targets: list<array{name: string, path: string, repository: string, branch: string}>, commands: list<list<string>>, diagnostics: list<string>}
     */
    public function split(string $rootPath, bool $dryRun = true, array $only = [], ?string $branchOverride = null): array
    {
        $root = rtrim($rootPath, '/');
        $splitFile = $root.'/split.json';

        if (! is_file($splitFile)) {
            return [
                'ok' => false,
                'targets' => [],
                'commands' => [],
                'diagnostics' => ["Missing split configuration file: {$splitFile}"],
            ];
        }

        $raw = file_get_contents($splitFile);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'targets' => [],
                'commands' => [],
                'diagnostics' => ['Invalid JSON in split.json'],
            ];
        }

        $defaultBranch = is_string($decoded['default_branch'] ?? null) ? $decoded['default_branch'] : 'main';
        $rawTargets = $decoded['packages'] ?? $decoded['targets'] ?? $decoded['splits'] ?? $decoded;

        if (! is_array($rawTargets)) {
            $rawTargets = [];
        }

        $targets = [];
        $commands = [];
        $diagnostics = [];

        foreach ($rawTargets as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $keyStr = is_string($key) ? $key : '';
            $name = is_string($entry['name'] ?? null) ? $entry['name'] : $keyStr;
            $path = is_string($entry['path'] ?? null)
                ? $entry['path']
                : (is_string($entry['prefix'] ?? null) ? $entry['prefix'] : $keyStr);
            $repo = is_string($entry['repository'] ?? null)
                ? $entry['repository']
                : (is_string($entry['repo'] ?? null)
                    ? $entry['repo']
                    : (is_string($entry['remote'] ?? null) ? $entry['remote'] : ''));
            $branch = $branchOverride
                ?? (is_string($entry['branch'] ?? null) ? $entry['branch'] : $defaultBranch);

            if ($name === '' || $path === '') {
                continue;
            }

            if ($only !== [] && ! in_array($name, $only, true) && ! in_array(basename($path), $only, true)) {
                continue;
            }

            $fullPath = $root.'/'.trim($path, '/');

            if (! is_dir($fullPath)) {
                $diagnostics[] = "[{$name}] Split directory missing: {$path}";

                continue;
            }

            $composerFile = $fullPath.'/composer.json';

            if (is_file($composerFile)) {
                $pkgRaw = file_get_contents($composerFile);
                $pkgJson = is_string($pkgRaw) ? json_decode($pkgRaw, true) : null;

                if (is_array($pkgJson) && is_array($pkgJson['repositories'] ?? null)) {
                    foreach ($pkgJson['repositories'] as $repoDef) {
                        if (is_array($repoDef) && ($repoDef['type'] ?? null) === 'path') {
                            $diagnostics[] = "[{$name}] Package composer.json must not embed local path repositories for subtree split.";
                        }
                    }
                }
            }

            $cleanPath = trim($path, '/');
            $splitBranch = 'split/'.str_replace(['/', '@'], '-', $name);
            $cmd = ['git', 'subtree', 'split', "--prefix={$cleanPath}", "--branch={$splitBranch}"];

            $targets[] = [
                'name' => $name,
                'path' => $cleanPath,
                'repository' => $repo,
                'branch' => $branch,
            ];
            $commands[] = $cmd;

            if (! $dryRun) {
                $process = new Process($cmd, $root);
                $process->setTimeout(120);
                $process->run();

                if (! $process->isSuccessful()) {
                    $err = $process->getErrorOutput();
                    $detail = $err !== '' ? $err : $process->getOutput();
                    $diagnostics[] = "[{$name}] Subtree split failed: ".trim($detail);
                }
            }
        }

        if ($targets === [] && $only !== []) {
            $diagnostics[] = 'No split targets matched --only filter: '.implode(', ', $only);
        }

        return [
            'ok' => $diagnostics === [],
            'targets' => $targets,
            'commands' => $commands,
            'diagnostics' => $diagnostics,
        ];
    }
}
