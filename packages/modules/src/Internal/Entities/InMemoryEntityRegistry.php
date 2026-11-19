<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Entities;

use Closure;
use Packman\Attributes\Internal;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Contracts\Entities\EntityRegistry;
use Packman\Modules\Data\Entities\EntityDefinition;
use Packman\Modules\Enums\Entities\Capability;
use Packman\Modules\Exceptions\Entities\EntityNotFound;
use Packman\Modules\Exceptions\Entities\RegistryFrozen;

/**
 * @internal
 * In-memory EntityRegistry. Module providers register() their entities at
 * boot; the compiled hot-path registry replaces this for production reads
 * once RegistryCompiler exists.
 */
#[Internal]
final class InMemoryEntityRegistry implements EntityRegistry
{
    private bool $frozen = false;

    /** @var array<string, EntityDefinition> */
    private array $entities = [];

    /** @var Closure(string, ContextScopeInterface):bool */
    private readonly Closure $moduleActive;

    /**
     * @param  Closure(string, ContextScopeInterface):bool|null  $moduleActive  Real active-module
     *                                                                          predicate (ModuleRegistry::isActive).
     *                                                                          Takes both the module composer name AND the
     *                                                                          tenant ctx — activity is per-account.
     * @param  Closure(ContextScopeInterface):list<string>|null  $activeModules  One active-module snapshot per list operation.
     */
    public function __construct(?Closure $moduleActive = null, private readonly ?Closure $activeModules = null)
    {
        $this->moduleActive = $moduleActive ?? static fn (string $module, ContextScopeInterface $ctx): bool => true;
    }

    public function register(EntityDefinition $definition): void
    {
        if ($this->frozen) {
            throw RegistryFrozen::late(point: 'entity', id: $definition->handle);
        }

        if (isset($this->entities[$definition->handle])) {
            throw RegistryFrozen::duplicate(point: 'entity', id: $definition->handle);
        }

        $this->entities[$definition->handle] = $definition;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function get(string $handle): EntityDefinition
    {
        return $this->entities[$handle] ?? throw new EntityNotFound($handle);
    }

    public function has(string $handle): bool
    {
        return isset($this->entities[$handle]);
    }

    public function all(?ContextScopeInterface $ctx = null): array
    {
        // No ctx = the WHOLE registry: the morph map and satellite
        // wiring span every declared entity, including those of currently
        // disabled modules — a persisted row of a disabled module must still
        // resolve its morph alias. The active-module filter only applies when
        // a ctx is given (per-account activity).
        if ($ctx === null) {
            return array_values($this->entities);
        }

        $active = $this->activeModules === null ? null : array_fill_keys(($this->activeModules)($ctx), true);
        $memo = [];

        return array_values(array_filter(
            $this->entities,
            function (EntityDefinition $definition) use ($ctx, $active, &$memo): bool {
                return $active !== null
                    ? isset($active[$definition->module])
                    : ($memo[$definition->module] ??= ($this->moduleActive)($definition->module, $ctx));
            },
        ));
    }

    public function byCapability(Capability $cap, ?ContextScopeInterface $ctx = null): array
    {
        return array_values(array_filter(
            $this->all($ctx),
            fn (EntityDefinition $definition): bool => in_array($cap, $definition->capabilities, true),
        ));
    }

    public function forModel(string $modelClass): ?EntityDefinition
    {
        foreach ($this->entities as $definition) {
            if ($definition->model === $modelClass) {
                return $definition;
            }
        }

        return null;
    }
}
