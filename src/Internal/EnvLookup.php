<?php

declare(strict_types=1);

namespace Packman\Testkit\Internal;

use Illuminate\Support\Env;
use Packman\Attributes\Internal;

/** Reads trimmed environment values across live getenv() and Laravel's Env repository without Facade roots. */
#[Internal]
final class EnvLookup
{
    public static function string(string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $raw = getenv($key);

            if ($raw === false) {
                $raw = Env::get($key);
            }

            if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
                continue;
            }

            $trimmed = trim((string) $raw);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    public static function bool(string $key): bool
    {
        $raw = getenv($key);

        if ($raw === false) {
            $raw = Env::get($key);
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (! is_string($raw) && ! is_int($raw)) {
            return false;
        }

        return filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function rawNumeric(string $key): ?string
    {
        $value = self::string($key);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return $value;
    }
}
