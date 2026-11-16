<?php

declare(strict_types=1);

namespace Packman\Schema\Data;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Schema\Internal\Services\Fingerprint;

/**
 * The install fingerprint stored in `{prefix}schema_state` under key `fingerprint`. A changed prefix makes the own
 * state table invisible, so the check also looks for the state table of another prefix in the same schema.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class SchemaFingerprint
{
    public const string KEY = 'fingerprint';

    public const string ID_STRATEGY = 'uuid7';

    public const int SCHEMA_VERSION = 1;

    /** @return array{prefix: string, id_strategy: string, schema_version: int, hash: string} */
    public static function document(string $prefix): array
    {
        $identity = ['prefix' => $prefix, 'id_strategy' => self::ID_STRATEGY, 'schema_version' => self::SCHEMA_VERSION];

        return $identity + ['hash' => Fingerprint::of($identity)];
    }

    /** @throws InvalidConfiguration when the database was installed with another prefix or id strategy */
    public static function verify(ConnectionInterface $connection, string $prefix, string $configPath = 'packman.schema.table_prefix'): void
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
            self::compare(self::stored($connection, $own), $prefix, $configPath);

            return;
        }

        foreach ($names as $name) {
            $stored = self::stored($connection, $name);

            // Only a Packman state table records its own prefix; anything else is a host table with a similar name.
            $storedPrefix = $stored['prefix'] ?? null;

            if (is_string($storedPrefix) && "{$storedPrefix}schema_state" === $name && isset($stored['id_strategy'])) {
                self::compare($stored, $prefix, $configPath);
            }
        }
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
