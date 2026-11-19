<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Services;

use InvalidArgumentException;
use Packman\Attributes\Internal;

/**
 * Deterministic names: database identifiers, space slugs, step ids and migration names.
 */
#[Internal]
final class SchemaNames
{
    public const int MAX_IDENTIFIER = 63;

    public const string STEP_ID = '/^(\d{4}_\d{2}_\d{2}_\d{6})_([a-z][a-z0-9_]{0,63})$/';

    public const string COMPONENT_ID = '/^[a-z][a-z0-9_]{0,23}$/';

    public const string LOGICAL_TABLE = '/^[a-z][a-z0-9_]{0,39}$/';

    public const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Name of an index, constraint, function or trigger of a table: "{databaseTable}_{suffix}", or, when longer than
     * 63 bytes, its first 54 bytes + '_' + the first 8 hex digits of its sha256 (63 bytes in total).
     */
    public static function identifier(string $databaseTable, string $suffix): string
    {
        $full = $databaseTable.'_'.$suffix;

        if (strlen($full) <= self::MAX_IDENTIFIER) {
            return $full;
        }

        return substr($full, 0, self::MAX_IDENTIFIER - 9).'_'.substr(hash('sha256', $full), 0, 8);
    }

    /** Space id as it appears in migration names: lower case, runs of other characters become '_'. */
    public static function spaceSlug(string $spaceId): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($spaceId)), '_');
    }

    /** @return array{0: string, 1: string} [timestamp, name] */
    public static function parseStepId(string $stepId): array
    {
        if (preg_match(self::STEP_ID, $stepId, $m) !== 1) {
            throw new InvalidArgumentException("Schema step id [{$stepId}] must be YYYY_MM_DD_HHMMSS_<snake_name>.");
        }

        return [$m[1], $m[2]];
    }

    /** "{timestamp}_{namespaceSlug}_{spaceSlug}_{component}_{name}" — Laravel orders migrations by this string. */
    public static function migration(string $spaceSlug, string $component, string $stepId, string $namespaceSlug = 'packman'): string
    {
        [$at, $name] = self::parseStepId($stepId);

        return "{$at}_{$namespaceSlug}_{$spaceSlug}_{$component}_{$name}";
    }
}
