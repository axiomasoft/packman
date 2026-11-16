<?php

declare(strict_types=1);

namespace Packman\Testkit\Fakes\Models;

use Packman\Attributes\Api;

/** Lightweight immutable actor identity fixture for authorization, audit, and tenancy tests. */
#[Api(since: '1.0')]
final readonly class FixtureActor
{
    /** @param list<string> $roles */
    public function __construct(
        public string $id,
        public string $name = 'Fixture Actor',
        public ?string $tenantId = null,
        public array $roles = [],
    ) {}
}
