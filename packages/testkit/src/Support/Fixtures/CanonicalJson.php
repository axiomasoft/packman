<?php

declare(strict_types=1);

namespace Packman\Testkit\Support\Fixtures;

use JsonException;
use Packman\Attributes\Api;

/** Deterministic recursive-sorted JSON encoder and structural equivalence helper. */
#[Api(since: '1.0')]
final class CanonicalJson
{
    /** @throws JsonException */
    public static function encode(mixed $value, bool $pretty = false): string
    {
        $normalized = self::normalize($value);
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($normalized, $flags);
    }

    /** @throws JsonException */
    public static function same(mixed $left, mixed $right): bool
    {
        return self::encode($left) === self::encode($right);
    }

    public static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        $copy = [];
        foreach ($value as $key => $entry) {
            $copy[(string) $key] = self::normalize($entry);
        }

        ksort($copy, SORT_STRING);

        return $copy;
    }
}
