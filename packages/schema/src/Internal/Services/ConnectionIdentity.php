<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Services;

use Illuminate\Database\ConfigurationUrlParser;

/**
 * Which physical database (and PostgreSQL schema) a Laravel connection writes to.
 */
final class ConnectionIdentity
{
    /** @param  array<string, mixed>  $config */
    public static function of(string $name, array $config): string
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        $driver = Values::string($config['driver'] ?? '');

        return match ($driver) {
            'sqlite' => self::sqlite($name, Values::string($config['database'] ?? '')),
            'pgsql' => sprintf('pgsql://%s:%s/%s?schema=%s', self::host($config), Values::string($config['port'] ?? '5432'), Values::string($config['database'] ?? ''), self::searchPath($config)),
            'mysql' => sprintf('mysql://%s:%s/%s', self::host($config), Values::string($config['port'] ?? '3306'), Values::string($config['database'] ?? '')),
            default => "unsupported:{$driver}@{$name}",
        };
    }

    public static function isSupported(string $identity): bool
    {
        return ! str_starts_with($identity, 'unsupported:');
    }

    private static function sqlite(string $name, string $database): string
    {
        if (str_contains($database, 'mode=memory') && str_contains($database, 'cache=shared')) {
            return 'sqlite::memory:'.hash('sha256', $database);
        }

        if ($database === '' || $database === ':memory:' || str_contains($database, 'mode=memory')) {
            return "sqlite::memory:@{$name}";
        }

        $real = realpath($database);

        return 'sqlite:'.($real === false ? $database : $real);
    }

    /** @param  array<string, mixed>  $config */
    private static function host(array $config): string
    {
        $write = $config['write'] ?? null;
        $host = (is_array($write) ? ($write['host'] ?? null) : null) ?? $config['host'] ?? 'localhost';

        if (is_array($host)) {
            $host = $host[0] ?? 'localhost';
        }

        return strtolower(Values::string($host));
    }

    /** @param  array<string, mixed>  $config */
    private static function searchPath(array $config): string
    {
        $path = $config['search_path'] ?? $config['schema'] ?? 'public';
        $first = is_array($path) ? Values::string($path[0] ?? 'public') : trim(explode(',', Values::string($path))[0]);

        $schema = trim($first, "\"' ");

        return $schema !== '' ? $schema : 'public';
    }
}
