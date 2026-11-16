<?php

declare(strict_types=1);

namespace Packman\Config\Internal;

use Packman\Attributes\Internal;

/**
 * Everything one compilation learned besides the settings objects: problems, consumed keys
 * (for closed-schema checks), key schema metadata, and active unsafe paths.
 */
#[Internal]
final class CompileJournal
{
    /** @var array<string, string> path => message (first problem per path wins) */
    private array $problems = [];

    /** @var array<string, true> */
    private array $consumed = [];

    /** @var array<string, true> */
    private array $containers = [];

    /** @var array<string, array<string, mixed>> */
    private array $schema = [];

    /** @var list<string> */
    private array $unsafe = [];

    public function problem(string $path, string $message): void
    {
        $this->problems[$path] ??= $message;
    }

    public function consume(string $path): void
    {
        $this->consumed[$path] = true;
    }

    public function container(string $path): void
    {
        $this->containers[$path] = true;
    }

    public function isContainer(string $path): bool
    {
        return isset($this->containers[$path]);
    }

    /** @param array<string, mixed> $entry */
    public function describe(string $path, array $entry): void
    {
        $this->schema[$path] ??= ['path' => $path] + $entry;
    }

    public function unsafe(string $path): void
    {
        $this->unsafe[] = $path;
    }

    public function isConsumed(string $path): bool
    {
        if (isset($this->consumed[$path])) {
            return true;
        }

        return array_any($this->consumed, fn (true $unused, string $consumed): bool => str_starts_with($path, $consumed.'.'));
    }

    /** @return list<string> "<path>: <message>", sorted by path */
    public function problems(): array
    {
        ksort($this->problems);
        $out = [];
        foreach ($this->problems as $path => $message) {
            $out[] = "{$path}: {$message}";
        }

        return $out;
    }

    /** @return list<array<string, mixed>> sorted by path; map entries use '*' for the data key */
    public function schema(): array
    {
        $rows = $this->schema;
        ksort($rows);

        return array_values($rows);
    }

    /** @return list<string> */
    public function unsafePaths(): array
    {
        $paths = array_values(array_unique($this->unsafe));
        sort($paths);

        return $paths;
    }

    /** @return list<string> sibling key names already described under $parent (for suggestions) */
    public function knownChildren(string $parent): array
    {
        $names = [];
        foreach (array_keys($this->schema) as $path) {
            if (str_starts_with($path, $parent.'.')) {
                $names[] = explode('.', substr($path, strlen($parent) + 1))[0];
            }
        }

        return array_values(array_unique($names));
    }
}
