<?php

declare(strict_types=1);

namespace Packman\Testkit\Support\Database;

use Packman\Attributes\Api;

/** Preserves canonical ordering of repeated group/batch keys across unordered DB scans. */
#[Api(since: '1.0')]
final class PreserveRepeatedGroups
{
    /**
     * Groups rows by key in first-seen group order while preserving insertion order inside each group.
     *
     * @template TItem
     * @template TKey of array-key
     *
     * @param  iterable<TItem>  $items
     * @param  callable(TItem): TKey  $keyResolver
     * @return list<TItem>
     */
    public static function reorder(iterable $items, callable $keyResolver): array
    {
        /** @var array<TKey, list<TItem>> $buckets */
        $buckets = [];

        foreach ($items as $item) {
            $buckets[$keyResolver($item)][] = $item;
        }

        /** @var list<TItem> $ordered */
        $ordered = [];
        foreach ($buckets as $group) {
            foreach ($group as $entry) {
                $ordered[] = $entry;
            }
        }

        return $ordered;
    }
}
