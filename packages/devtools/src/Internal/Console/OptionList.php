<?php

declare(strict_types=1);

namespace Packman\DevTools\Internal\Console;

use Packman\Attributes\Internal;

/** Normalizes repeatable or comma-separated CLI filter options into a clean string list. */
#[Internal]
final class OptionList
{
    /** @return list<string> */
    public static function parse(mixed $raw): array
    {
        if (! is_array($raw)) {
            return is_string($raw) ? self::splitComma($raw) : [];
        }

        $items = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                foreach (self::splitComma($entry) as $part) {
                    $items[] = $part;
                }
            }
        }

        return array_values(array_unique($items));
    }

    /** @return list<string> */
    private static function splitComma(string $value): array
    {
        $parts = [];
        foreach (explode(',', $value) as $segment) {
            $trimmed = trim($segment);

            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }

        return $parts;
    }
}
