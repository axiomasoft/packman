<?php

declare(strict_types=1);

namespace Packman\Testkit\Exceptions\Database;

use Packman\Attributes\Api;
use RuntimeException;

/** Thrown when a test suite targets an unsafe database or attempts a destructive reset without explicit opt-in. */
#[Api(since: '1.0')]
final class UnsafeTestDatabaseException extends RuntimeException
{
    public static function refused(string $driver, string $host, string $database, string $reason): self
    {
        return new self(sprintf(
            'Refusing destructive test operation against %s database "%s" on host "%s": %s. Target a *_test database on a local host and set PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 for destructive resets.',
            $driver,
            $database,
            $host,
            $reason,
        ));
    }
}
