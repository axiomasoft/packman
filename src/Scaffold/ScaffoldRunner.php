<?php

declare(strict_types=1);

namespace Packman\DevTools\Scaffold;

use Closure;
use Packman\Attributes\Api;
use RuntimeException;
use Throwable;

/**
 * Executes 4-phase transactional scaffolding: plan -> collision preflight -> atomic staged rename -> post-write hook.
 */
#[Api(since: '1.0')]
final readonly class ScaffoldRunner
{
    /**
     * @param  Closure(): list<GeneratedFile>  $plan
     * @param  (Closure(list<array{path: string, relativePath: string, overwritten: bool}>): void)|null  $afterWrite
     * @return list<array{path: string, relativePath: string, overwritten: bool}>
     */
    public function run(
        string $basePath,
        Closure $plan,
        ?Closure $afterWrite = null,
    ): array {
        $files = $plan();
        $existing = [];
        $planned = [];
        /** @var array<string, true> $seenTargets */
        $seenTargets = [];

        foreach ($files as $file) {
            $target = self::normalizePath(Layout::absolute($basePath, $file->relativePath));

            if (is_dir($target)) {
                throw ScaffoldCollisionException::targetIsDirectory($target);
            }

            if (isset($seenTargets[$target])) {
                $existing[] = $file->relativePath;

                continue;
            }

            $seenTargets[$target] = true;

            if (is_file($target) && ! $file->overwrite) {
                $existing[] = $file->relativePath;
            }

            $planned[] = [
                'target' => $target,
                'file' => $file,
            ];
        }

        if ($existing !== []) {
            throw ScaffoldCollisionException::existingFiles($existing);
        }

        $written = $this->write($planned);
        $results = [];
        foreach ($written as $index => $entry) {
            $results[] = [
                'path' => $entry['path'],
                'relativePath' => $files[$index]->relativePath,
                'overwritten' => $entry['overwritten'],
            ];
        }

        if ($afterWrite !== null) {
            $afterWrite($results);
        }

        return $results;
    }

    /**
     * Atomically stages and commits planned files via POSIX rename, rolling back all committed files on failure.
     *
     * @param  list<array{target: string, file: GeneratedFile}>  $planned
     * @return list<array{path: string, overwritten: bool}>
     */
    public function write(array $planned): array
    {
        $results = [];
        $staged = [];
        $committed = [];
        /** @var array<string, true> $seenTargets */
        $seenTargets = [];

        try {
            foreach ($planned as $entry) {
                $target = self::normalizePath($entry['target']);

                if (isset($seenTargets[$target])) {
                    throw ScaffoldCollisionException::existingFiles([$entry['target']]);
                }

                $seenTargets[$target] = true;
                $file = $entry['file'];
                $dir = dirname($target);

                if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                    throw new RuntimeException('Unable to create directory '.$dir.'.');
                }

                $overwritten = is_file($target);

                if ($overwritten && ! $file->overwrite) {
                    throw ScaffoldCollisionException::existingFiles([$target]);
                }

                $tmp = tempnam($dir, '.packman-scaffold-');

                if ($tmp === false) {
                    throw new RuntimeException('Unable to create temporary file in '.$dir.'.');
                }

                if (file_put_contents($tmp, $file->contents) === false) {
                    @unlink($tmp);

                    throw new RuntimeException('Unable to write staged scaffold file for '.$target.'.');
                }

                $perms = $overwritten ? fileperms($target) : false;
                $mode = is_int($perms) ? ($perms & 0o777) : (0o666 & ~umask());
                @chmod($tmp, $mode);

                $backup = null;

                if ($overwritten) {
                    $raw = file_get_contents($target);

                    if ($raw !== false) {
                        $backup = $raw;
                    }
                }

                $staged[] = [
                    'tmp' => $tmp,
                    'target' => $target,
                    'overwritten' => $overwritten,
                    'backup' => $backup,
                ];
            }

            foreach ($staged as $index => $item) {
                if (is_dir($item['target']) || ! @rename($item['tmp'], $item['target'])) {
                    throw new RuntimeException('Unable to move scaffold file into place: '.$item['target'].'.');
                }

                unset($staged[$index]);
                $committed[] = $item;
                $results[] = [
                    'path' => $item['target'],
                    'overwritten' => $item['overwritten'],
                ];
            }

            return $results;
        } catch (Throwable $error) {
            foreach (array_reverse($committed) as $item) {
                if ($item['overwritten'] && $item['backup'] !== null) {
                    @file_put_contents($item['target'], $item['backup']);
                } elseif (! $item['overwritten'] && is_file($item['target'])) {
                    @unlink($item['target']);
                }
            }

            throw $error;
        } finally {
            foreach ($staged as $item) {
                if (is_file($item['tmp'])) {
                    @unlink($item['tmp']);
                }
            }
        }
    }

    private static function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($normalized, '/');
        $segments = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments !== [] && $segments[count($segments) - 1] !== '..') {
                    array_pop($segments);
                } elseif (! $isAbsolute) {
                    $segments[] = '..';
                }

                continue;
            }

            $segments[] = $segment;
        }

        return ($isAbsolute ? '/' : '').implode('/', $segments);
    }
}
