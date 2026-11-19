<?php

declare(strict_types=1);

namespace Packman\Foundation;

use Packman\Attributes\Api;
use SplMaxHeap;

/** Stable topological ordering. Blocked nodes have a missing prerequisite, form a cycle, or depend on one. */
#[Api(since: '0.1.1')]
final readonly class DependencyOrder
{
    /**
     * @param  list<string>  $ordered
     * @param  list<string>  $blocked
     */
    private function __construct(public array $ordered, public array $blocked) {}

    /**
     * @param  array<string, list<string>>  $dependencies  Node IDs in tie-break order, mapped to prerequisite IDs.
     */
    public static function resolve(array $dependencies): self
    {
        /** @var SplMaxHeap<int> $ready */
        $ready = new SplMaxHeap;
        $nodes = array_keys($dependencies);
        $position = [];
        $pending = [];
        $dependents = [];
        foreach ($dependencies as $node => $prerequisites) {
            $position[$node] = count($position);
            $unique = array_unique($prerequisites);
            $pending[$node] = count($unique);
            foreach ($unique as $prerequisite) {
                $dependents[$prerequisite][] = $node;
            }

            if ($pending[$node] === 0) {
                $ready->insert(-$position[$node]);
            }
        }

        $ordered = [];
        while (! $ready->isEmpty()) {
            $node = $nodes[-$ready->extract()];
            $ordered[] = $node;
            unset($pending[$node]);
            foreach ($dependents[$node] ?? [] as $dependent) {
                if (--$pending[$dependent] === 0) {
                    $ready->insert(-$position[$dependent]);
                }
            }
        }

        return new self($ordered, array_keys($pending));
    }
}
