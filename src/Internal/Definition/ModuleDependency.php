<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Definition;

/**
 * ModuleManifest dependency declaration: a module or a PHP extension.
 * `requires()` deps mirror composer `require` — the compiler rejects a
 * mismatch; `suggests()` deps are soft hints only.
 *
 * @internal spec: B-10 §3.2, P1.4
 */
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
