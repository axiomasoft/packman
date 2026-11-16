<?php

declare(strict_types=1);

namespace Packman\Testkit\Fakes\Models;

use Packman\Attributes\Api;

/** Lightweight immutable tenant/organization owner fixture for multi-owner tests. */
#[Api(since: '1.0')]
final readonly class FixtureOwner
{
    public function __construct(
        public string $id,
        public string $slug = 'fixture-owner',
        public string $displayName = 'Fixture Owner',
    ) {}
}
