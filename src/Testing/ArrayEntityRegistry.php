<?php

declare(strict_types=1);

namespace Packman\Modules\Testing;

use Closure;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Contracts\EntityRegistry;
use Packman\Modules\Data\EntityDefinition;
use Packman\Modules\Enums\Capability;
use Packman\Modules\Internal\InMemoryEntityRegistry;

/**
 * In-memory EntityRegistry. Module providers register() their entities at
 * boot; the compiled hot-path registry replaces this for production reads
 * once RegistryCompiler exists.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ArrayEntityRegistry implements EntityRegistry
{
    private InMemoryEntityRegistry $registry;

    /**
     * @param  Closure(string, ContextScopeInterface):bool|null  $moduleActive  Real active-module
     *                                                                          predicate (ModuleRegistry::isActive).
     *                                                                          Takes both the module composer name AND the
     *                                                                          tenant ctx — activity is per-account.
     * @param  Closure(ContextScopeInterface):list<string>|null  $activeModules  One active-module snapshot per list operation.
     */
    public function __construct(?Closure $moduleActive = null, private ?Closure $activeModules = null)
    {
        $this->registry = new InMemoryEntityRegistry(moduleActive: $moduleActive, activeModules: $activeModules);
    }

    public function register(EntityDefinition $definition): void
    {
        $this->registry->register($definition);
    }

    public function freeze(): void
    {
        $this->registry->freeze();
    }

    public function get(string $handle): EntityDefinition
    {
        return $this->registry->get($handle);
    }

    public function has(string $handle): bool
    {
        return $this->registry->has($handle);
    }

    public function all(?ContextScopeInterface $ctx = null): array
    {
        return $this->registry->all($ctx);
    }

    public function byCapability(Capability $cap, ?ContextScopeInterface $ctx = null): array
    {
        return $this->registry->byCapability(cap: $cap, ctx: $ctx);
    }

    public function forModel(string $modelClass): ?EntityDefinition
    {
        return $this->registry->forModel($modelClass);
    }
}
