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
        $fullPath = rtrim($this->stubsRoot, '/').'/'.ltrim($stubRelativePath, '/');

        if (! is_file($fullPath)) {
            throw new RuntimeException("Scaffold stub not found: {$fullPath}");
        }

        $template = file_get_contents($fullPath);

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
            $classes,
            static fn (string $class): bool => trim($class) !== '',
        )));
        sort($unique, SORT_STRING);

        return implode("\n", array_map(
            static fn (string $class): string => "use {$class};",
            $unique,
        ));
    }
}
