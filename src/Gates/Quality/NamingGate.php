<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Quality;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Internal\Gates\GateFileWalker;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;

/**
 * Verifies strict_types declarations, valid composer package names, and PSR-4 class-to-file naming alignment.
 */
#[Api(since: '1.0')]
final readonly class NamingGate implements QualityGate
{
    private const string COMPOSER_NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\/[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    private GateFileWalker $walker;

    public function __construct()
    {
        $this->walker = new GateFileWalker;
    }

    public function id(): string
    {
        return 'naming';
    }

    public function category(): string
    {
        return 'quality';
    }

    public function description(): string
    {
        return 'Enforces strict_types=1, valid Composer package names, and PSR-4 filename-to-symbol alignment.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $checkedFiles = 0;

        foreach ($context->config->packages as $package) {
            if (preg_match(self::COMPOSER_NAME_PATTERN, $package->name) !== 1) {
                $violations[] = "Invalid Composer package name '{$package->name}' in packman.json.";
            }

            $srcDir = $context->packageDirectory($package).'/src';
            $baseNamespace = trim($package->namespace, '\\');
            $psr4Roots = $this->resolveSrcPsr4Roots($context->readComposerJson($package), $baseNamespace);

            foreach ($this->walker->phpFiles($srcDir) as $file) {
                $checkedFiles++;
                $rel = $this->walker->relativePath($context->rootPath, $file);
                $ast = $this->walker->parseAst($file);

                if ($ast === null) {
                    $violations[] = "[{$package->name}] Unable to parse PHP source file {$rel}.";

                    continue;
                }

                if (! $this->hasStrictTypes($ast)) {
                    $violations[] = "[{$package->name}] Missing declare(strict_types=1); in {$rel}.";
                }

                $subPath = ltrim(substr($file, strlen($srcDir)), '/');
                $expectedShortName = pathinfo($subPath, PATHINFO_FILENAME);
                $expectedNamespace = $this->expectedNamespaceForSubPath($subPath, $psr4Roots);

                [$actualNs, $symbolNames] = $this->extractNamespaceAndTypes($ast);

                if ($symbolNames === []) {
                    continue;
                }

                if ($actualNs !== $expectedNamespace) {
                    $violations[] = sprintf(
                        '[%s] PSR-4 namespace mismatch in %s: expected "%s", found "%s".',
                        $package->name,
                        $rel,
                        $expectedNamespace,
                        $actualNs ?? '(global)',
                    );
                }

                if (! in_array($expectedShortName, $symbolNames, true)) {
                    $violations[] = sprintf(
                        '[%s] Primary symbol mismatch in %s: expected type named "%s", found [%s].',
                        $package->name,
                        $rel,
                        $expectedShortName,
                        implode(', ', $symbolNames),
                    );
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), [sprintf('Verified strict_types and PSR-4 naming across %d source files.', $checkedFiles)])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }

    /**
     * @param  array<mixed>  $composer
     * @return list<array{prefix: string, namespace: string}>
     */
    private function resolveSrcPsr4Roots(array $composer, string $baseNamespace): array
    {
        $roots = [];
        $autoload = is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $psr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];

        foreach ($psr4 as $ns => $path) {
            if (! is_string($ns) || ! is_string($path)) {
                continue;
            }

            $normalizedPath = trim(str_replace('\\', '/', $path), '/');

            if ($normalizedPath === 'src') {
                $roots[] = ['prefix' => '', 'namespace' => trim($ns, '\\')];
            } elseif (str_starts_with($normalizedPath, 'src/')) {
                $roots[] = ['prefix' => substr($normalizedPath, 4).'/', 'namespace' => trim($ns, '\\')];
            }
        }

        if ($roots === []) {
            $roots[] = ['prefix' => '', 'namespace' => $baseNamespace];
        }

        usort($roots, static fn (array $a, array $b): int => strlen($b['prefix']) <=> strlen($a['prefix']));

        return $roots;
    }

    /** @param list<array{prefix: string, namespace: string}> $roots */
    private function expectedNamespaceForSubPath(string $subPath, array $roots): string
    {
        foreach ($roots as $root) {
            if ($root['prefix'] === '' || str_starts_with($subPath, $root['prefix'])) {
                $remainder = substr($subPath, strlen($root['prefix']));
                $relativeDir = dirname($remainder);

                return $relativeDir === '.'
                    ? $root['namespace']
                    : $root['namespace'].'\\'.str_replace('/', '\\', $relativeDir);
            }
        }

        $relativeDir = dirname($subPath);
        $fallback = $roots[0]['namespace'] ?? '';

        return $relativeDir === '.'
            ? $fallback
            : $fallback.'\\'.str_replace('/', '\\', $relativeDir);
    }

    /** @param list<Node\Stmt> $ast */
    private function hasStrictTypes(array $ast): bool
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Declare_) {
                foreach ($stmt->declares as $declare) {
                    if ($declare->key->toString() === 'strict_types') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  list<Node\Stmt>  $ast
     * @return array{0: ?string, 1: list<string>}
     */
    private function extractNamespaceAndTypes(array $ast): array
    {
        $namespace = null;
        $names = [];

        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $namespace = $stmt->name?->toString();
                foreach ($stmt->stmts as $inner) {
                    if (($inner instanceof Class_ || $inner instanceof Interface_ || $inner instanceof Trait_ || $inner instanceof Enum_) && $inner->name !== null) {
                        $names[] = $inner->name->toString();
                    }
                }
            }
        }

        return [$namespace, $names];
    }
}
