<?php

declare(strict_types=1);

namespace Packman\Schema\Data\Compiler;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Index and constraint names: `{table}_{cols}_{ix|uq|fk|ck}`, `{table}_{name}_ex` and `{table}_pk`. A name longer than 63 bytes keeps its
 * first 54 bytes + '_' + the first 8 hex digits of the sha1 of the full name. Frozen: released migrations used it.
 */
#[Api(since: '1.0')]
final class ConstraintNames
{
    /** PostgreSQL keeps 63 bytes of an identifier (NAMEDATALEN - 1). */
    public const int MAX_IDENTIFIER = 63;

    /** @param  string|list<string>  $columns */
    public static function index(string $table, string|array $columns): string
    {
        return self::name($table, self::columns($columns), 'ix');
    }

    /** @param  string|list<string>  $columns */
    public static function unique(string $table, string|array $columns): string
    {
        return self::name($table, self::columns($columns), 'uq');
    }

    /** @param  string|list<string>  $columns */
    public static function foreign(string $table, string|array $columns): string
    {
        return self::name($table, self::columns($columns), 'fk');
    }

    /** `$name` describes the rule, e.g. `quantity_positive`. */
    public static function check(string $table, string $name): string
    {
        return self::name($table, self::part($name), 'ck');
    }

    /** `$name` describes the rule, e.g. `window_overlap`; names an `EXCLUDE USING gist` constraint. */
    public static function exclusion(string $table, string $name): string
    {
        return self::name($table, self::part($name), 'ex');
    }

    public static function primary(string $table): string
    {
        return self::shorten("{$table}_pk");
    }

    /** Deterministic shortening of any identifier to 63 bytes. */
    public static function shorten(string $name): string
    {
        if (strlen($name) <= self::MAX_IDENTIFIER) {
            return $name;
        }

        return substr($name, 0, self::MAX_IDENTIFIER - 9).'_'.substr(sha1($name), 0, 8);
    }

    private static function name(string $table, string $middle, string $suffix): string
    {
        return self::shorten(self::part($table)."_{$middle}_{$suffix}");
    }

    /** @param  string|list<string>  $columns */
    private static function columns(string|array $columns): string
    {
        $columns = is_string($columns) ? [$columns] : $columns;

        if ($columns === []) {
            throw new InvalidArgumentException('A constraint needs at least one column.');
        }

        return implode('_', array_map(self::part(...), $columns));
    }

    private static function part(string $identifier): string
    {
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/', $identifier) !== 1) {
            throw new InvalidArgumentException("'{$identifier}' is not a lower-case SQL identifier.");
        }

        return $identifier;
    }
}
