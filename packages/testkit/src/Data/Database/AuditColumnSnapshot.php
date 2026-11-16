<?php

declare(strict_types=1);

namespace Packman\Testkit\Data\Database;

use Packman\Attributes\Api;

/** Snapshot of one database column metadata row inspected via AuditTable. */
#[Api(since: '1.0')]
final readonly class AuditColumnSnapshot
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public ?string $default = null,
    ) {}
}
