<?php

declare(strict_types=1);

namespace Packman\Testkit\Exceptions\Database;

use Packman\Attributes\Api;
use RuntimeException;

/** Thrown when a test suite targets a non-test database without explicit destructive opt-in. */
#[Api(since: '1.0')]
final class UnsafeTestDatabaseException extends RuntimeException
{
    public static function refused(string $driver, string $host, string $database, string $reason): self
    {
        return new self(sprintf(
            'Refusing destructive test operation against %s database "%s" on host "%s": %s. Set PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 or target a *_test database.',
            $driver,
            $database,
            $host,
            $reason,
        ));
    }
}
