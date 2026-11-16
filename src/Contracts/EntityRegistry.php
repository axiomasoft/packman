<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts;

use Packman\Attributes\Api;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Data\EntityDefinition;
use Packman\Modules\Enums\Capability;
use Packman\Modules\Exceptions\EntityNotFound;

/**
 * Central registry of ecosystem entities. Single source of the polymorphic
 * morph map (ADR-003) and the contract satellites (search, audit,
 * attributes, workflow, comments, documents) query via byCapability() to
 * wire themselves without knowing the owning module.
 */
#[Api(since: '1.0')]
interface EntityRegistry
{
    /** @throws EntityNotFound */
    public function get(string $handle): EntityDefinition;

    public function has(string $handle): bool;

    /** @return list<EntityDefinition> active for the tenant (owning module enabled) */
    public function all(?ContextScopeInterface $ctx = null): array;

    /** @return list<EntityDefinition> */
    public function byCapability(Capability $cap, ?ContextScopeInterface $ctx = null): array;

    /** @param class-string $modelClass */
    public function forModel(string $modelClass): ?EntityDefinition;
}
