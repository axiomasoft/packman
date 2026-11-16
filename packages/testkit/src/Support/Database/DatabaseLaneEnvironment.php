<?php

declare(strict_types=1);

namespace Packman\Testkit\Support\Database;

use Packman\Attributes\Api;
use Packman\Testkit\Internal\EnvLookup;

/** Resolves worker-specific database/schema lane identifiers when tests run in parallel. */
#[Api(since: '1.0')]
final class DatabaseLaneEnvironment
{
    public static function perProcessEnabled(): bool
    {
        return EnvLookup::bool('PACKMAN_TEST_DB_PER_PROCESS');
    }

    public static function destructiveOptIn(): bool
    {
        return EnvLookup::bool('PACKMAN_TEST_DESTRUCTIVE_OPT_IN');
    }

    public static function workerToken(): string
    {
        $value = EnvLookup::string('PARA_TEST_TOKEN', 'TEST_TOKEN');

        if ($value !== null) {
            return preg_replace('/[^a-zA-Z0-9_]+/', '_', $value) ?? 'main';
        }

        if (self::perProcessEnabled()) {
            return (string) getmypid();
        }

        return 'main';
    }

    public static function isolatedDatabaseName(string $baseDatabase): string
    {
        if (! self::perProcessEnabled()) {
            return $baseDatabase;
        }

        $token = self::workerToken();

        if ($token === 'main') {
            return $baseDatabase;
        }

        return str_ends_with($baseDatabase, '_test')
            ? substr($baseDatabase, 0, -5)."_{$token}_test"
            : "{$baseDatabase}_{$token}_test";
    }
}
