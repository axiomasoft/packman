<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Architecture;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Data\Gates\PackageManifest;
use Packman\DevTools\Internal\Gates\GateFileWalker;

/**
 * Enforces acyclic L1->L2->L3->L4 layer dependencies and declared composer edges without dev-only leakage.
 */
#[Api(since: '1.0')]
final readonly class PackageGraphGate implements QualityGate
{
    private GateFileWalker $walker;

    public function __construct()
    {
        $this->walker = new GateFileWalker;
    }

    public function id(): string
    {
        return 'package-graph';
    }

    public function category(): string
    {
        return 'architecture';
    }

    public function description(): string
    {
        return 'Verifies acyclic layer DAG and ensures runtime packages never depend on dev-only or undeclared packages.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        /** @var array<string, list<string>> $edges */
        $edges = [];

        foreach ($context->config->packages as $package) {
            $edges[$package->name] = [];
            $composer = $context->readComposerJson($package);

            $require = is_array($composer['require'] ?? null) ? array_keys($composer['require']) : [];
            $requireDev = is_array($composer['require-dev'] ?? null) ? array_keys($composer['require-dev']) : [];
            $suggest = is_array($composer['suggest'] ?? null) ? array_keys($composer['suggest']) : [];
            $declared = array_values(array_unique(array_merge($require, $suggest)));

            foreach ($require as $depName) {
                $target = $context->config->findPackageByName((string) $depName);

                if (! $target instanceof PackageManifest) {
                    continue;
                }

                $edges[$package->name][] = $target->name;

                if (! $package->devOnly && $target->devOnly) {
                    $violations[] = "[{$package->name}] Runtime package requires dev-only package [{$target->name}] in composer.json require.";
                }

                if ($target->layerRank() > $package->layerRank()) {
                    $violations[] = "[{$package->name}] ({$package->layer}) cannot depend upward on [{$target->name}] ({$target->layer}).";
                }
            }

            foreach ($requireDev as $depName) {
                $target = $context->config->findPackageByName((string) $depName);

                if ($target instanceof PackageManifest && ! $package->devOnly && ! $target->devOnly && $target->layerRank() > $package->layerRank()) {
                    $violations[] = "[{$package->name}] ({$package->layer}) require-dev references upward layer [{$target->name}] ({$target->layer}).";
                }
            }

            $srcDir = $context->packageDirectory($package).'/src';
            foreach ($this->walker->phpFiles($srcDir) as $file) {
                $rel = $this->walker->relativePath($context->rootPath, $file);
                foreach ($this->walker->importedSymbols($file) as $fqcn) {
                    $target = $context->owners->ownerOfClass($fqcn);

                    if (! $target instanceof PackageManifest || $target->name === $package->name) {
                        continue;
                    }

                    if (! $package->devOnly && $target->devOnly) {
                        $violations[] = "[{$package->name}] Source file {$rel} imports dev-only symbol [{$fqcn}] from [{$target->name}].";
                    }

                    if ($target->layerRank() > $package->layerRank()) {
                        $violations[] = "[{$package->name}] Source file {$rel} imports higher layer [{$target->name}] ({$target->layer}): {$fqcn}.";
                    }

                    if (! in_array($target->name, $declared, true)) {
                        $violations[] = "[{$package->name}] Source file {$rel} imports [{$target->name}] ({$fqcn}) without declaring it in composer.json require/suggest.";
                    }
                }
            }
        }

        foreach ($this->findCycles($edges) as $cycle) {
            $violations[] = 'Cyclic package dependency detected: '.implode(' -> ', $cycle);
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), [sprintf('Verified %d packages in acyclic layer graph.', count($context->config->packages))])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }

    /**
     * @param  array<string, list<string>>  $edges
     * @return list<list<string>>
     */
    private function findCycles(array $edges): array
    {
        $state = [];
        $stack = [];
        $cycles = [];

        foreach (array_keys($edges) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $this->dfs($node, $edges, $state, $stack, $cycles);
            }
        }

        return $cycles;
    }

    /**
     * @param  array<string, list<string>>  $edges
     * @param  array<string, int>  $state
     * @param  list<string>  $stack
     * @param  list<list<string>>  $cycles
     */
    private function dfs(string $node, array $edges, array &$state, array &$stack, array &$cycles): void
    {
        $state[$node] = 1;
        $stack[] = $node;

        foreach (array_unique($edges[$node] ?? []) as $next) {
            $nextState = $state[$next] ?? 0;

            if ($nextState === 1) {
                $pos = array_search($next, $stack, true);

                if (is_int($pos)) {
                    $cycles[] = [...array_slice($stack, $pos), $next];
                }
            } elseif ($nextState === 0) {
                $this->dfs($next, $edges, $state, $stack, $cycles);
            }
        }

        array_pop($stack);
        $state[$node] = 2;
    }
}
