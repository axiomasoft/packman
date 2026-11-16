<?php

declare(strict_types=1);

namespace Packman\DevTools\Scaffold;

use Packman\Attributes\Api;

/** Canonical profile metadata and layout descriptor used by MakeCommand scaffolding. */
#[Api(since: '1.0')]
final readonly class ProfileDescriptor
{
    /** @param list<string> $allowedDirectories */
    public function __construct(
        public string $name,
        public string $description,
        public array $allowedDirectories,
    ) {}
}
