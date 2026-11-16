<?php

declare(strict_types=1);

namespace Packman\Testkit\Internal;

use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Packman\Attributes\Internal;
use Packman\Testkit\Data\Database\TestDatabaseIdentity;
use Packman\Testkit\Exceptions\Database\UnsafeTestDatabaseException;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use ReflectionProperty;

/**
 * Inspects the actual resolved Laravel database connection before destructive test operations.
 */
#[Internal]
final class ResolvedDatabaseConnection
{
    /** @var list<string> */
    private const array SUB_CONNECTION_ROLES = ['read', 'write', 'direct'];

    public static function assertDestructiveSafe(string $connection, ?string $requiredDriver = null): Connection
    {
        [$baseConnection] = self::parseConnectionName($connection);

        $rawConnections = Config::get('database.connections', []);
        $rawEntry = is_array($rawConnections) ? Arr::get($rawConnections, $baseConnection) : null;
        $rawConfig = self::parseConfigEntry($rawEntry);

        if ($rawConfig !== []) {
            self::assertRawConfigSafe($rawConfig, $connection, $requiredDriver);
        }

        $resolved = DB::connection($connection);
        $driver = strtolower(trim($resolved->getDriverName()));
        $database = $resolved->getDatabaseName();
        $rawResolvedConfig = $resolved->getConfig();
        /** @var array<string, mixed> $resolvedConfig */
        $resolvedConfig = is_array($rawResolvedConfig) ? $rawResolvedConfig : [];

        self::assertTargetsSafe(
            driver: $driver,
            hosts: self::hostsForConfig($resolvedConfig),
            port: self::extractPort($resolvedConfig['port'] ?? null),
            database: $database,
            username: is_string($resolvedConfig['username'] ?? null) ? $resolvedConfig['username'] : '',
            schema: self::extractSchema($resolvedConfig),
            connection: $connection,
            requiredDriver: $requiredDriver,
        );

        /** @var array<string, mixed> $baseDefaults */
        $baseDefaults = $rawConfig !== []
            ? Arr::except($rawConfig, self::SUB_CONNECTION_ROLES)
            : Arr::except($resolvedConfig, self::SUB_CONNECTION_ROLES);
        $baseDefaults['driver'] ??= $driver;
        $baseDefaults['database'] ??= $database;
        $baseDefaults['host'] ??= $resolvedConfig['host'] ?? null;
        $baseDefaults['port'] ??= $resolvedConfig['port'] ?? 0;
        $baseDefaults['username'] ??= $resolvedConfig['username'] ?? '';

        self::assertSubConnectionsSafe($resolvedConfig, $baseDefaults, $driver, $connection, $requiredDriver);

        $directConfig = $resolved->getDirectPdoConfig();

        if ($directConfig !== []) {
            /** @var array<string, mixed> $directConfig */
            self::assertMergedConfigSafe($directConfig, $driver, $connection, $requiredDriver);
        }

        $readConfig = new ReflectionProperty(Connection::class, 'readPdoConfig')->getValue($resolved);

        if (is_array($readConfig) && $readConfig !== []) {
            /** @var array<string, mixed> $readConfig */
            self::assertMergedConfigSafe($readConfig, $driver, $connection, $requiredDriver);
        }

        return $resolved;
    }

    /** @param array<string, mixed> $rawConfig */
    private static function assertRawConfigSafe(
        array $rawConfig,
        string $connection,
        ?string $requiredDriver,
    ): void {
        /** @var array<string, mixed> $baseDefaults */
        $baseDefaults = Arr::except($rawConfig, self::SUB_CONNECTION_ROLES);
        $fallbackDriver = is_string($baseDefaults['driver'] ?? null)
            ? strtolower(trim($baseDefaults['driver']))
            : '';

        $baseDatabase = is_string($baseDefaults['database'] ?? null) ? trim($baseDefaults['database']) : '';

        if ($baseDatabase !== '' || ! isset($rawConfig['read'], $rawConfig['write'])) {
            self::assertMergedConfigSafe($baseDefaults, $fallbackDriver, $connection, $requiredDriver);
        }

        self::assertSubConnectionsSafe($rawConfig, $baseDefaults, $fallbackDriver, $connection, $requiredDriver);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $baseDefaults
     */
    private static function assertSubConnectionsSafe(
        array $config,
        array $baseDefaults,
        string $fallbackDriver,
        string $connection,
        ?string $requiredDriver,
    ): void {
        foreach (self::SUB_CONNECTION_ROLES as $role) {
            foreach (self::expandRoleEntries($config[$role] ?? null) as $roleEntry) {
                $parsedEntry = self::parseConfigEntry($roleEntry);

                if ($parsedEntry === []) {
                    continue;
                }

                $merged = array_merge($baseDefaults, $parsedEntry);
                self::assertMergedConfigSafe($merged, $fallbackDriver, $connection, $requiredDriver);
            }
        }
    }

    /** @return array{0: string, 1: ?string} */
    private static function parseConnectionName(string $connection): array
    {
        foreach (self::SUB_CONNECTION_ROLES as $role) {
            $suffix = '::'.$role;

            if (str_ends_with($connection, $suffix)) {
                return [substr($connection, 0, -strlen($suffix)), $role];
            }
        }

        return [$connection, null];
    }

    /** @return array<string, mixed> */
    private static function parseConfigEntry(mixed $config): array
    {
        if (is_string($config)) {
            $parsed = (new ConfigurationUrlParser)->parseConfiguration($config);

            /** @var array<string, mixed> $parsed */
            return $parsed;
        }

        if (! is_array($config) || $config === []) {
            return [];
        }

        $stringKeyed = [];

        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $stringKeyed[$key] = $value;
            }
        }

        if (isset($stringKeyed['url']) && is_string($stringKeyed['url'])) {
            $parsed = (new ConfigurationUrlParser)->parseConfiguration($stringKeyed);

            /** @var array<string, mixed> $parsed */
            return $parsed;
        }

        return $stringKeyed;
    }

    /** @return list<array<string, mixed>|string> */
    private static function expandRoleEntries(mixed $roleConfig): array
    {
        if (is_string($roleConfig) && trim($roleConfig) !== '') {
            return [$roleConfig];
        }

        if (! is_array($roleConfig) || $roleConfig === []) {
            return [];
        }

        if (array_is_list($roleConfig) || isset($roleConfig[0])) {
            $entries = [];

            foreach ($roleConfig as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $entries[] = $item;

                    continue;
                }

                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $entries[] = $item;
                }
            }

            return $entries;
        }

        /** @var array<string, mixed> $roleConfig */
        return [$roleConfig];
    }

    /** @param array<string, mixed> $config */
    private static function assertMergedConfigSafe(
        array $config,
        string $fallbackDriver,
        string $connection,
        ?string $requiredDriver,
    ): void {
        $driver = is_string($config['driver'] ?? null)
            ? strtolower(trim($config['driver']))
            : $fallbackDriver;
        $database = is_string($config['database'] ?? null) ? $config['database'] : '';
        $username = is_string($config['username'] ?? null) ? $config['username'] : '';

        self::assertTargetsSafe(
            driver: $driver,
            hosts: self::hostsForConfig($config),
            port: self::extractPort($config['port'] ?? null),
            database: $database,
            username: $username,
            schema: self::extractSchema($config),
            connection: $connection,
            requiredDriver: $requiredDriver,
        );
    }

    /** @param list<string> $hosts */
    private static function assertTargetsSafe(
        string $driver,
        array $hosts,
        int $port,
        string $database,
        string $username,
        ?string $schema,
        string $connection,
        ?string $requiredDriver,
    ): void {
        foreach ($hosts as $host) {
            $identity = new TestDatabaseIdentity(
                driver: $driver,
                host: $host,
                port: $port,
                database: $database,
                username: $username,
                schema: $schema,
            );

            TestDatabaseGuard::assertDestructiveSafe($identity);

            if ($requiredDriver !== null && $driver !== strtolower($requiredDriver)) {
                throw UnsafeTestDatabaseException::refused(
                    $identity->driver,
                    $identity->host,
                    $identity->database,
                    sprintf('expected %s driver for connection "%s"', $requiredDriver, $connection),
                );
            }
        }
    }

    private static function extractPort(mixed $portRaw): int
    {
        return is_int($portRaw) || is_string($portRaw) ? (int) $portRaw : 0;
    }

    /** @param array<string, mixed> $config */
    private static function extractSchema(array $config): ?string
    {
        $schemaRaw = $config['search_path'] ?? $config['schema'] ?? null;

        return is_string($schemaRaw) ? $schemaRaw : null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function hostsForConfig(array $config): array
    {
        $candidates = [
            $config['host'] ?? null,
            Arr::get($config, 'read.host'),
            Arr::get($config, 'write.host'),
            Arr::get($config, 'direct.host'),
        ];

        $hosts = [];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $hosts[] = $candidate;

                continue;
            }

            if (! is_array($candidate)) {
                continue;
            }

            foreach ($candidate as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $hosts[] = $item;
                }
            }
        }

        return $hosts === [] ? [''] : array_values(array_unique($hosts));
    }
}
