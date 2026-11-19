<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Services;

use BackedEnum;
use InvalidArgumentException;
use JsonSerializable;
use Packman\Attributes\Internal;
use Stringable;

/**
 * sha256 (64 hex) of canonical JSON of a payload.
 */
#[Internal]
final class Fingerprint
{
    public static function of(mixed $payload): string
    {
        return hash('sha256', json_encode(
            self::canonical($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof JsonSerializable) {
            return self::canonical($value->jsonSerialize());
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('A fingerprint payload holds only scalars, null, arrays, backed enums, Stringable or JsonSerializable values; got '.get_debug_type($value).'.');
        }

        $canonical = array_map(self::canonical(...), $value);

        if (array_is_list($canonical)) {
            return $canonical;
        }

        ksort($canonical, SORT_STRING);

        return (object) $canonical;
    }
}
