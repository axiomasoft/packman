<?php

declare(strict_types=1);

namespace Packman\DevTools\Scaffold;

use Packman\Attributes\Api;
use RuntimeException;

/**
 * Loads scaffolding stubs, renders placeholders strictly (failing on unresolved tokens), and sorts imports.
 */
#[Api(since: '1.0')]
final readonly class StubStore
{
    public function __construct(private string $stubsRoot) {}

    public static function defaultRoot(): string
    {
        return dirname(__DIR__, 2).'/stubs';
    }

    /** @param array<string, string> $variables */
    public function render(string $stubRelativePath, array $variables): string
    {
        $relative = str_replace('\\', '/', $stubRelativePath);

        if ($relative === '' || str_starts_with($relative, '/') || str_contains('/'.$relative.'/', '/../')) {
            throw new RuntimeException('Scaffold stub path must stay inside the stub root.');
        }

        $fullPath = rtrim($this->stubsRoot, '/').'/'.$relative;
        $root = realpath($this->stubsRoot);
        $resolved = realpath($fullPath);

        if ($root === false || $resolved === false || ! is_file($resolved)) {
            throw new RuntimeException("Scaffold stub not found: {$fullPath}");
        }

        if (! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Scaffold stub path must stay inside the stub root.');
        }

        $template = file_get_contents($resolved);

        if (! is_string($template)) {
            throw new RuntimeException("Unable to read scaffold stub: {$fullPath}");
        }

        $rendered = $template;
        foreach ($variables as $key => $value) {
            $rendered = str_replace('{{ '.$key.' }}', $value, $rendered);
            $rendered = str_replace('{{'.$key.'}}', $value, $rendered);
        }

        if (preg_match('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', $rendered, $matches) === 1) {
            throw new RuntimeException(sprintf(
                'Unresolved placeholder %s in rendered stub [%s].',
                $matches[0],
                $stubRelativePath,
            ));
        }

        return $rendered;
    }

    /** @param list<string> $classes */
    public static function imports(array $classes): string
    {
        $unique = array_values(array_unique(array_filter(
            array_map(static fn (string $class): string => ltrim(trim($class), '\\'), $classes),
            static fn (string $class): bool => $class !== '',
        )));
        usort($unique, static fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

        return implode("\n", array_map(
            static fn (string $class): string => "use {$class};",
            $unique,
        ));
    }
}
