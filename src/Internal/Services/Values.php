<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Services;

use Stringable;
use UnexpectedValueException;

/** Validates untyped framework values before they enter typed schema data. */
final class Values
{
    public static function string(mixed $value): string
    {
        if ($value === null || is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new UnexpectedValueException('Expected a scalar or stringable value, got '.get_debug_type($value).'.');
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        return array_map(self::string(...), array_values((array) $value));
    }
}
