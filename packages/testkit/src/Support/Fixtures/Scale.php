<?php

declare(strict_types=1);

namespace Packman\Testkit\Support\Fixtures;

use Packman\Attributes\Api;
use Packman\Testkit\Internal\EnvLookup;

/** Computes bounded scale counts for stress/concurrency tests controlled via PACKMAN_TEST_SCALE. */
#[Api(since: '1.0')]
final class Scale
{
    public static function factor(): float
    {
        $raw = EnvLookup::rawNumeric('PACKMAN_TEST_SCALE');

        if ($raw === null) {
            return 1.0;
        }

        $value = (float) $raw;

        return $value > 0.0 ? $value : 1.0;
    }

    public static function count(int $baseline, int $minimum = 1): int
    {
        return max($minimum, (int) round($baseline * self::factor()));
    }
}
