<?php

declare(strict_types=1);

namespace Packman\Testkit\Data\Database;

use Packman\Attributes\Api;

/** Normalized connection descriptor inspected by TestDatabaseGuard. */
#[Api(since: '1.0')]
final readonly class TestDatabaseIdentity
{
    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public ?string $schema = null,
    ) {}
}
