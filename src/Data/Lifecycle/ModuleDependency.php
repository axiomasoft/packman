<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Lifecycle;

use Packman\Attributes\Api;

/**
 * ModuleManifest dependency declaration: a module or a PHP extension.
 * `requires()` deps mirror composer `require` — the compiler rejects a
 * mismatch; `suggests()` deps are soft hints only.
 */
#[Api(since: '0.1.1')]
final readonly class ModuleDependency
{
    private function __construct(
        public string $kind,
        public string $package,
        public string $constraint,
    ) {}

    public static function module(string $composerName, string $constraint = '*'): self
    {
        return new self(kind: 'module', package: $composerName, constraint: $constraint);
    }
}
