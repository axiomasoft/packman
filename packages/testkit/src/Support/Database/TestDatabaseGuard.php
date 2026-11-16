<?php

declare(strict_types=1);

namespace Packman\Testkit\Support\Database;

use Packman\Attributes\Api;
use Packman\Testkit\Data\Database\TestDatabaseIdentity;
use Packman\Testkit\Exceptions\Database\UnsafeTestDatabaseException;

/** Refuses destructive test database operations against non-test or production-looking targets. */
#[Api(since: '1.0')]
final class TestDatabaseGuard
{
    /** @var list<string> */
    private const array RESERVED_DATABASES = [
        'postgres',
        'template0',
        'template1',
        'mysql',
        'sys',
        'information_schema',
        'performance_schema',
        'prod',
        'production',
        'staging',
        'live',
        'main',
    ];

    /** @var list<string> */
    private const array SAFE_LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', 'postgres', 'pgsql', 'db', 'sqlite'];

    public static function assertSafe(TestDatabaseIdentity $identity, ?bool $optInOverride = null): void
    {
        $driver = strtolower(trim($identity->driver));
        $database = trim($identity->database);
        $lowerDatabase = strtolower($database);
        $host = strtolower(trim($identity->host));

        if ($driver === 'sqlite' && ($database === ':memory:' || str_contains($lowerDatabase, 'sqlite') || str_contains($lowerDatabase, 'test'))) {
            return;
        }

        if ($database === '') {
            throw UnsafeTestDatabaseException::refused($identity->driver, $identity->host, $identity->database, 'database name cannot be empty');
        }

        if (in_array($lowerDatabase, self::RESERVED_DATABASES, true)) {
            throw UnsafeTestDatabaseException::refused($identity->driver, $identity->host, $identity->database, 'reserved system or production database name is forbidden');
        }

        if (preg_match('/(^|[_.-])(prod|production|live|staging)([_.-]|$)/', $lowerDatabase) === 1) {
            throw UnsafeTestDatabaseException::refused($identity->driver, $identity->host, $identity->database, 'database name matches a production/staging pattern');
        }

        $optIn = $optInOverride ?? DatabaseLaneEnvironment::destructiveOptIn();
        $hasTestSuffix = str_ends_with($lowerDatabase, '_test') || str_ends_with($lowerDatabase, '_testing');

        if (! $hasTestSuffix && ! $optIn) {
            throw UnsafeTestDatabaseException::refused($identity->driver, $identity->host, $identity->database, 'database name must end with _test or _testing');
        }

        if (! $optIn && $host !== '' && ! in_array($host, self::SAFE_LOCAL_HOSTS, true) && ! str_starts_with($host, '127.')) {
            throw UnsafeTestDatabaseException::refused($identity->driver, $identity->host, $identity->database, 'non-local host requires explicit PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1');
        }
    }
}
