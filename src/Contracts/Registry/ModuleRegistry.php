<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts\Registry;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Data\Compiler\CompiledModule;
use Packman\Modules\Data\Compiler\CompiledRegistry;
use Packman\Modules\Exceptions\Registry\ModuleNotFound;

/**
 * Compiled code-level registry: built by `modules:compile` at deploy
 * time, read from a file cache — 0 filesystem scans, 0 SQL queries on a
 * warm hot path.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ModuleRegistry
{
    /** @return list<CompiledModule> every module in the codebase */
    public function all(): array;

    /** @throws ModuleNotFound */
    public function get(string $name): CompiledModule;

    /** @return list<CompiledModule> intersection with the tenant's enabled set (mod_modules) */
    public function active(ContextScopeInterface $ctx): array;

    public function isActive(string $name, ContextScopeInterface $ctx): bool;

    /**
     * The full compiled registry (all 10 slices, including `settings`/
     * `featureFlags`), read from the same compiled file. The runtime
     * projects `entities` into EntityRegistry and the morph map from this;
     * downstream consumers of the other slices live outside this package.
     * Exposed as the whole {@see CompiledRegistry} (not just `entities()`)
     * because the projection, morph map and lifecycle all read different
     * slices of it.
     */
    public function compiled(): CompiledRegistry;
}
