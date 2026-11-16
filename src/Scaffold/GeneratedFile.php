<?php

declare(strict_types=1);

namespace Packman\DevTools\Scaffold;

use Packman\Attributes\Api;

/** Immutable generated artifact produced by scaffolding or code synthesis. */
#[Api(since: '1.0')]
final readonly class GeneratedFile
{
    public function __construct(
        public string $relativePath,
        public string $contents,
        public bool $overwrite = false,
    ) {}
}
