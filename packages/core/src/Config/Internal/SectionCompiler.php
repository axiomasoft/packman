<?php

declare(strict_types=1);

namespace Packman\Config\Internal;

use Packman\Attributes\Internal;
use Packman\Config\Contracts\ConfigSection;
use Packman\Config\Data\CompileContext;
use Packman\Config\Exceptions\InvalidConfiguration;

/**
 * Pure closed-schema section compiler: collects all validation errors, rejects unknown keys with
 * Levenshtein typo suggestions, and blocks unsafe settings in production unless explicitly opted in.
 */
#[Internal]
final readonly class SectionCompiler
{
    public function __construct(
        private string $unsafeHint = 'packman.unsafe.allow_in_production / PACKMAN_ALLOW_UNSAFE=true',
    ) {}

    /**
     * @param  list<ConfigSection>  $sections
     * @param  array<string, mixed>  $roots
     */
    public function compile(array $sections, array $roots, CompileContext $context): CompiledSections
    {
        $journal = new CompileJournal;
        $settings = [];

        foreach ($sections as $section) {
            $key = $section->key();
            $class = $section->settingsClass();

            if (array_key_exists($class, $settings)) {
                $journal->problem($key, "settings class {$class} is produced by two sections");

                continue;
            }

            $subtree = $this->subtree($roots, $key);

            if (! is_array($subtree)) {
                $journal->problem($key, 'must be an array, got '.get_debug_type($subtree));
                $subtree = [];
            }

            $prefix = '';
            foreach (explode('.', $key) as $segment) {
                $prefix = ltrim("{$prefix}.{$segment}", '.');
                $journal->container($prefix);
            }

            $object = $section->compile(new JournalingConfigReader($key, $subtree, $journal, $context));

            if (! $object instanceof $class) {
                $journal->problem($key, $section::class.'::compile() must return '.$class.', got '.$object::class);

                continue;
            }

            $settings[$class] = $object;
        }

        foreach ($this->rootsOf($sections) as $root) {
            self::reportUnknown($roots[$root] ?? [], $root, $journal);
        }

        if ($context->isProduction() && ! $context->allowUnsafe) {
            foreach ($journal->unsafePaths() as $path) {
                $journal->problem($path, "unsafe setting is not allowed in production (set {$this->unsafeHint} to accept the risk)");
            }
        }

        $problems = $journal->problems();

        if ($problems !== []) {
            throw InvalidConfiguration::fromProblems($problems);
        }

        return new CompiledSections($settings, $journal->schema(), $journal->unsafePaths());
    }

    /** @param array<string, mixed> $roots */
    private function subtree(array $roots, string $key): mixed
    {
        $node = $roots;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return [];
            }
            $node = $node[$segment];
        }

        return $node ?? [];
    }

    /**
     * @param  list<ConfigSection>  $sections
     * @return list<string>
     */
    private function rootsOf(array $sections): array
    {
        $roots = [];
        foreach ($sections as $section) {
            $roots[explode('.', $section->key())[0]] = true;
        }

        return array_keys($roots);
    }

    private static function reportUnknown(mixed $node, string $path, CompileJournal $journal): void
    {
        if ($journal->isConsumed($path)) {
            return;
        }

        if (! $journal->isContainer($path)) {
            $journal->problem($path, 'unknown key'.self::suggestion($path, $journal));

            return;
        }

        if (! is_array($node) || array_is_list($node)) {
            return;
        }

        foreach ($node as $key => $child) {
            self::reportUnknown($child, "{$path}.{$key}", $journal);
        }
    }

    private static function suggestion(string $path, CompileJournal $journal): string
    {
        $cut = strrpos($path, '.');

        if ($cut === false) {
            return '';
        }

        $name = substr($path, $cut + 1);
        $best = null;
        $bestDistance = 4;

        foreach ($journal->knownChildren(substr($path, 0, $cut)) as $candidate) {
            $distance = levenshtein($name, $candidate);

            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$candidate, $distance];
            }
        }

        return $best === null ? '' : " (did you mean \"{$best}\"?)";
    }
}
