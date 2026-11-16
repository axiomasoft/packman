<?php

declare(strict_types=1);

namespace Packman\DevTools\Sync;

use Packman\Attributes\Api;

/**
 * Synchronizes canonical ai/ rule and agent packs to .claude/, .codex/agents/*.toml, .gemini/, and AGENTS.md.
 *
 * Emits Codex v0.169+-compliant TOML ([skills] BundledSkillsConfig table, never array skills or command_requirements),
 * preserves *-reviewer-overrides.md files, and skips gracefully when no canonical ai/ source directory exists.
 */
#[Api(since: '1.0')]
final class EcosystemAiSynchronizer
{
    /** @var list<string> */
    private const array MANAGED_OUTPUT_GLOBS = [
        '.claude/rules/*.md',
        '.claude/agents/*.md',
        '.codex/agents/*.toml',
        '.gemini/rules/*.md',
    ];

    /**
     * @return array{
     *   ok: bool,
     *   status: 'skipped_missing_source'|'dry_run'|'synced',
     *   planned: list<string>,
     *   written: list<string>,
     *   pruned: list<string>,
     *   preserved: list<string>,
     *   missing: list<string>,
     *   drifted: list<string>,
     *   orphans: list<string>,
     *   notes: list<string>
     * }
     */
    public function sync(string $rootPath, bool $dryRun = false): array
    {
        $root = rtrim($rootPath, '/');
        $aiRoot = $root.'/ai';

        if (! is_dir($aiRoot)) {
            return [
                'ok' => true,
                'status' => 'skipped_missing_source',
                'planned' => [],
                'written' => [],
                'pruned' => [],
                'preserved' => [],
                'missing' => [],
                'drifted' => [],
                'orphans' => [],
                'notes' => ['No canonical ai/ directory found; skipping sync cleanly.'],
            ];
        }

        $planned = [];
        $written = [];
        $pruned = [];
        $missing = [];
        $drifted = [];
        $notes = [];
        $artifacts = [];

        $manifestPath = $aiRoot.'/manifest.json';
        $manifest = [];

        if (is_file($manifestPath)) {
            $raw = file_get_contents($manifestPath);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;

            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }

        $rules = $this->readMarkdownDirectory($aiRoot.'/rules');
        $agents = $this->readMarkdownDirectory($aiRoot.'/agents');

        foreach ($rules as $slug => $content) {
            $artifacts[".claude/rules/{$slug}.md"] = $content;
            $artifacts[".gemini/rules/{$slug}.md"] = $content;
        }

        $preserved = $this->collectPreservedOverrides($root, $manifest);

        foreach ($agents as $slug => $content) {
            if (str_ends_with($slug, '-reviewer-overrides') && is_file("{$root}/.claude/agents/{$slug}.md")) {
                continue;
            }

            $artifacts[".claude/agents/{$slug}.md"] = $content;
            $artifacts[".codex/agents/{$slug}.toml"] = $this->renderCodexAgentToml($slug, $content);
        }

        $settingsPayload = [
            'pack' => is_string($manifest['name'] ?? null) ? $manifest['name'] : 'packman-ai-pack',
            'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : '1.0.0',
            'generatedBy' => 'packman ai:sync',
        ];
        $artifacts['.claude/settings.json'] = json_encode($settingsPayload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $lockPayload = [
            'schema' => 'packman-agent-pack/1',
            'pack' => $settingsPayload['pack'],
            'version' => $settingsPayload['version'],
            'rules' => array_keys($rules),
            'agents' => array_keys($agents),
            'checksum' => hash('sha256', implode("\n", array_keys($artifacts))."\n".implode("\n", array_values($artifacts))),
        ];
        $artifacts['.claude/agent-pack.lock.json'] = json_encode($lockPayload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $artifacts['AGENTS.md'] = $this->renderAgentsMarkdown($rules, $agents);

        ksort($artifacts, SORT_STRING);

        $allowed = array_flip(array_merge(array_keys($artifacts), $preserved));
        $orphans = $this->findOrphanArtifacts($root, $allowed);
        $writeOk = true;

        foreach ($artifacts as $relative => $body) {
            $planned[] = $relative;
            $target = $root.'/'.$relative;

            if ($dryRun) {
                if (! is_file($target)) {
                    $missing[] = $relative;
                    $notes[] = "Missing generated AI artifact: {$relative}";

                    continue;
                }

                $existing = file_get_contents($target);

                if ($existing !== $body) {
                    $drifted[] = $relative;
                    $notes[] = "Drifted generated AI artifact: {$relative}";
                }

                continue;
            }

            $dir = dirname($target);

            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            file_put_contents($target, $body);
            $written[] = $relative;
        }

        if ($dryRun) {
            foreach ($orphans as $orphan) {
                $notes[] = "Orphan (stale) AI artifact: {$orphan}";
            }
        } else {
            foreach ($orphans as $orphan) {
                $target = $root.'/'.$orphan;

                if (! is_file($target)) {
                    continue;
                }

                if (unlink($target)) {
                    $pruned[] = $orphan;
                    $notes[] = "Pruned obsolete AI artifact: {$orphan}";
                } else {
                    $writeOk = false;
                    $notes[] = "Failed to prune obsolete AI artifact: {$orphan}";
                }
            }
        }

        $ok = $dryRun ? ($missing === [] && $drifted === [] && $orphans === []) : $writeOk;

        if ($dryRun) {
            $notes[] = $ok
                ? sprintf(
                    'Verified %d AI sync artifacts (%d rules, %d agents, %d preserved overrides) with zero drift.',
                    count($planned),
                    count($rules),
                    count($agents),
                    count($preserved),
                )
                : sprintf(
                    'AI sync drift detected (%d missing, %d drifted, %d orphan out of %d planned artifacts). Run `bin/packman ai:sync`.',
                    count($missing),
                    count($drifted),
                    count($orphans),
                    count($planned),
                );
        } else {
            $notes[] = $pruned === []
                ? sprintf(
                    'Synchronized %d AI sync artifacts (%d rules, %d agents, %d preserved overrides).',
                    count($planned),
                    count($rules),
                    count($agents),
                    count($preserved),
                )
                : sprintf(
                    'Synchronized %d AI sync artifacts (%d rules, %d agents, %d preserved overrides, %d pruned).',
                    count($planned),
                    count($rules),
                    count($agents),
                    count($preserved),
                    count($pruned),
                );
        }

        return [
            'ok' => $ok,
            'status' => $dryRun ? 'dry_run' : 'synced',
            'planned' => $planned,
            'written' => $written,
            'pruned' => $pruned,
            'preserved' => $preserved,
            'missing' => $missing,
            'drifted' => $drifted,
            'orphans' => $dryRun ? $orphans : [],
            'notes' => $notes,
        ];
    }

    /**
     * @param  array<mixed>  $manifest
     * @return list<string>
     */
    private function collectPreservedOverrides(string $root, array $manifest): array
    {
        $preserved = [];
        $rawPreserved = glob($root.'/.claude/agents/*-reviewer-overrides.md');
        $preservedPaths = is_array($rawPreserved) ? $rawPreserved : [];

        foreach ($preservedPaths as $overridePath) {
            if (is_file($overridePath)) {
                $preserved[] = '.claude/agents/'.basename($overridePath);
            }
        }

        foreach (['preserve', 'preserved'] as $key) {
            $configured = $manifest[$key] ?? null;

            if (! is_array($configured)) {
                continue;
            }

            foreach ($configured as $entry) {
                if (! is_string($entry)) {
                    continue;
                }

                $relative = ltrim(trim($entry), '/');

                if ($relative !== '' && is_file($root.'/'.$relative)) {
                    $preserved[] = $relative;
                }
            }
        }

        $preserved = array_values(array_unique($preserved));
        sort($preserved, SORT_STRING);

        return $preserved;
    }

    /**
     * @param  array<string, int>  $allowed
     * @return list<string>
     */
    private function findOrphanArtifacts(string $root, array $allowed): array
    {
        $orphans = [];

        foreach (self::MANAGED_OUTPUT_GLOBS as $pattern) {
            $matches = glob($root.'/'.$pattern);

            if (! is_array($matches)) {
                continue;
            }

            foreach ($matches as $path) {
                if (! is_file($path)) {
                    continue;
                }

                $relative = ltrim(substr($path, strlen($root)), '/');

                if (! isset($allowed[$relative])) {
                    $orphans[] = $relative;
                }
            }
        }

        $orphans = array_values(array_unique($orphans));
        sort($orphans, SORT_STRING);

        return $orphans;
    }

    /** @return array<string, string> */
    private function readMarkdownDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $rawFiles = glob($directory.'/*.md');
        $files = is_array($rawFiles) ? $rawFiles : [];
        sort($files, SORT_STRING);
        $items = [];

        foreach ($files as $file) {
            $raw = file_get_contents($file);

            if (is_string($raw)) {
                $items[pathinfo($file, PATHINFO_FILENAME)] = $raw;
            }
        }

        return $items;
    }

    private function renderCodexAgentToml(string $slug, string $markdown): string
    {
        [$meta, $body] = $this->splitFrontmatter($markdown);

        $name = $this->escapeTomlString($meta['name'] ?? $slug);
        $description = $this->escapeTomlString($meta['description'] ?? "Packman {$slug} agent");
        $model = $this->escapeTomlString($meta['model'] ?? 'gpt-5.4');
        $effort = $this->escapeTomlString($meta['model_reasoning_effort'] ?? 'medium');
        $sandbox = $this->escapeTomlString($meta['sandbox_mode'] ?? 'workspace-write');

        $instructions = str_replace(['\\', '"""'], ['\\\\', '\\"""'], trim($body));
        $toml = <<<TOML
name = "{$name}"
description = "{$description}"
model = "{$model}"
model_reasoning_effort = "{$effort}"
sandbox_mode = "{$sandbox}"
developer_instructions = """
{$instructions}
"""
TOML;

        $skillsList = $this->parseSkillsList($meta['skills'] ?? '');

        if ($skillsList !== []) {
            $encodedSkills = implode(', ', array_map(
                fn (string $skill): string => '"'.$this->escapeTomlString($skill).'"',
                $skillsList,
            ));
            $toml .= "\n\n[skills]\nenabled = [{$encodedSkills}]";
        }

        return $toml."\n";
    }

    /**
     * @param  array<string, string>  $rules
     * @param  array<string, string>  $agents
     */
    private function renderAgentsMarkdown(array $rules, array $agents): string
    {
        $lines = [
            '# Repository Agent Instructions',
            '',
            'Generated automatically by `bin/packman ai:sync`. Do not edit generated agent specs in place; update `ai/` sources.',
            '',
        ];

        if ($rules !== []) {
            $lines[] = '## Rules';
            foreach (array_keys($rules) as $rule) {
                $lines[] = "- `.claude/rules/{$rule}.md` / `.gemini/rules/{$rule}.md`";
            }

            $lines[] = '';
        }

        if ($agents !== []) {
            $lines[] = '## Agents';
            foreach (array_keys($agents) as $agent) {
                $lines[] = "- `{$agent}` (`.codex/agents/{$agent}.toml`, `.claude/agents/{$agent}.md`)";
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function splitFrontmatter(string $markdown): array
    {
        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $markdown, $matches) !== 1) {
            return [[], $markdown];
        }

        $meta = [];
        $split = preg_split('/\R/', $matches[1]);
        $lines = is_array($split) ? $split : [];
        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$k, $v] = explode(':', $line, 2);
            $meta[trim($k)] = trim(trim($v), " \t'\"");
        }

        return [$meta, $matches[2]];
    }

    /** @return list<string> */
    private function parseSkillsList(string $raw): array
    {
        $clean = trim($raw, "[] \t");

        if ($clean === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $part): string => trim($part, " \t'\""), explode(',', $clean)),
            static fn (string $skill): bool => $skill !== '',
        ));
    }

    private function escapeTomlString(string $value): string
    {
        return str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', ''], $value);
    }
}
