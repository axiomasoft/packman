<?php

declare(strict_types=1);

namespace Packman\Testkit\Concerns\Database;

use Packman\Attributes\Api;

/** Allocates an isolated temporary SQLite database file per test and cleans it up afterwards. */
#[Api(since: '1.0')]
trait IsolatesDatabasePath
{
    /** @var list<string> */
    private array $isolatedSqlitePaths = [];

    protected function createIsolatedSqlitePath(string $prefix = 'packman-test-'): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8)).'.sqlite';
        touch($path);
        $this->isolatedSqlitePaths[] = $path;

        return $path;
    }

    protected function cleanupIsolatedDatabasePaths(): void
    {
        foreach ($this->isolatedSqlitePaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->isolatedSqlitePaths = [];
    }
}
