<?php

declare(strict_types=1);

namespace Packman\DevTools\Internal\Rector;

use Packman\Attributes\Internal;

/** Shared path fragment filter for boundary-aware Rector rules. */
#[Internal]
final class PreservedPaths
{
    /** @param list<string> $fragments */
    public static function matchesAny(string $filePath, array $fragments): bool
    {
        $normalized = str_replace('\\', '/', $filePath);

        return array_any($fragments, fn (string $fragment): bool => str_contains($normalized, $fragment));
    }
}
