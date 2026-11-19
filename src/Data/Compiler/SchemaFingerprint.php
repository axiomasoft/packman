<?php

declare(strict_types=1);

namespace Packman\Schema\Data\Compiler;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Schema\Internal\Services\Fingerprint;

/**
 * The install fingerprint stored in `{prefix}schema_state` under key `fingerprint`. A changed prefix makes the own
 * state table invisible, so the check also looks for another prefix recorded by the same owner.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class SchemaFingerprint
{
    public const string KEY = 'fingerprint';

    public const string ID_STRATEGY = 'uuid7';

    public const int SCHEMA_VERSION = 1;

    /** @return array{prefix: string, id_strategy: string, schema_version: int, owner?: string, hash: string} */
    public static function document(string $prefix, ?string $owner = null): array
    {
        $identity = ['prefix' => $prefix, 'id_strategy' => self::ID_STRATEGY, 'schema_version' => self::SCHEMA_VERSION];

        if ($owner !== null) {
            $identity['owner'] = $owner;
        }

        return $identity + ['hash' => Fingerprint::of($identity)];
    }

    /**
     * @param  (Closure(ConnectionInterface, string): bool)|null  $ownsLegacy  Identifies this package's older, unlabelled installations.
     *
     * @throws InvalidConfiguration when the database was installed with another prefix, owner or id strategy
     */
    public static function verify(ConnectionInterface $connection, string $prefix, string $configPath = 'packman.schema.table_prefix', ?string $owner = null, ?Closure $ownsLegacy = null): void
    {
        if ($connection instanceof Connection && $connection->getDriverName() !== 'pgsql') {
            return;
        }

        $tables = $connection->select(<<<'SQL'
            select c.relname as name
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where c.relkind in ('r', 'p')
              and n.nspname = current_schema()
              and c.relname ~ '^[a-z][a-z0-9]{1,6}_schema_state$'
              and exists (select 1 from pg_attribute a where a.attrelid = c.oid and a.attname = 'key' and not a.attisdropped)
              and exists (select 1 from pg_attribute a where a.attrelid = c.oid and a.attname = 'value' and not a.attisdropped)
            order by c.relname
            SQL, useReadPdo: false);

        $names = array_map(static fn (mixed $row): string => self::string($row, 'name'), $tables);
        $own = "{$prefix}schema_state";

        if (in_array($own, $names, true)) {
            $stored = self::stored($connection, $own);

            if ($stored !== null && ! self::belongsTo($stored, $owner, $ownsLegacy, $connection, $prefix, true)) {
                throw new InvalidConfiguration(["{$configPath}: the schema under prefix '{$prefix}' belongs to another package"]);
            }
            self::compare($stored, $prefix, $configPath);

            return;
        }

        foreach ($names as $name) {
            $stored = self::stored($connection, $name);

            // Only a Packman state table records its own prefix; anything else is a host table with a similar name.
            $storedPrefix = $stored['prefix'] ?? null;

            if (! is_string($storedPrefix) || "{$storedPrefix}schema_state" !== $name || ! isset($stored['id_strategy'])) {
                continue;
            }

            if (self::belongsTo($stored, $owner, $ownsLegacy, $connection, $storedPrefix, false)) {
                self::compare($stored, $prefix, $configPath);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $stored
     * @param  (Closure(ConnectionInterface, string): bool)|null  $ownsLegacy
     */
    private static function belongsTo(array $stored, ?string $owner, ?Closure $ownsLegacy, ConnectionInterface $connection, string $prefix, bool $configuredPrefix): bool
    {
        if (array_key_exists('owner', $stored)) {
            return $stored['owner'] === $owner;
        }

        if ($ownsLegacy !== null) {
            return $ownsLegacy($connection, $prefix);
        }

        return $configuredPrefix || $owner === null;
    }

    /** @param  array<array-key, mixed>|null  $stored */
    private static function compare(?array $stored, string $prefix, string $configPath): void
    {
        if ($stored === null) {
            return;
        }

        $storedPrefix = $stored['prefix'] ?? null;

        if ($storedPrefix !== $prefix) {
            $actual = is_string($storedPrefix) ? $storedPrefix : '?';

            throw new InvalidConfiguration([
                "{$configPath}: the database was installed with prefix '{$actual}', the configuration says '{$prefix}'; the prefix cannot change after installation",
            ]);
        }

        if (($stored['id_strategy'] ?? null) !== self::ID_STRATEGY) {
            throw new InvalidConfiguration([
                "{$configPath}: the schema under prefix '{$prefix}' was installed with an unknown id strategy",
            ]);
        }
    }

    /** @return array<array-key, mixed>|null */
    private static function stored(ConnectionInterface $connection, string $table): ?array
    {
        $row = $connection->selectOne(
            sprintf('select value::text as value from "%s" where key::text = ?', $table),
            [self::KEY],
            useReadPdo: false,
        );

        if ($row === null) {
            return null;
        }

        $value = json_decode(self::string($row, 'value'), true);

        return is_array($value) ? $value : null;
    }

    private static function string(mixed $row, string $column): string
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;

        return is_string($value) ? $value : '';
    }
}
