<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Catalogue;

use LogicException;
use Packman\Attributes\Internal;
use Packman\Modules\Contracts\Entities\EntityRegistry;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Data\Entities\EntityDefinition;
use Packman\Modules\Enums\Entities\Capability;
use Packman\Panels\Contracts\Registry\PanelCatalogue;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;
use Packman\Panels\Data\Subjects\EntityReference;
use Throwable;

/**
 * Panel catalogue wired to `Packman\Modules` when `packman-modules` is installed and bound.
 */
#[Internal]
final class ModulePanelCatalogue implements PanelCatalogue
{
    /** @var array<string, PanelProvider> */
    private array $providers = [];

    /** @var array<string, PanelDescriptor> */
    private array $descriptors = [];

    /** @param iterable<PanelProvider> $providers */
    public function __construct(
        iterable $providers,
        private readonly EntityRegistry $entities,
        private readonly ModuleRegistry $modules,
    ) {
        foreach ($providers as $provider) {
            $descriptor = $provider->descriptor();
            $key = $descriptor->key;

            if (isset($this->providers[$key])) {
                throw new LogicException("Duplicate panel provider key [{$key}].");
            }

            $this->providers[$key] = $provider;
            $this->descriptors[$key] = $descriptor;
        }
    }

    public function forEntity(PanelContext $context, EntityReference $entity): array
    {
        $definition = $this->activeEntity(context: $context, entity: $entity);

        if ($definition === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (PanelDescriptor $descriptor): ?PanelDescriptor => $this->visibleDescriptor(descriptor: $descriptor, context: $context, capabilities: $definition->capabilities),
            $this->descriptors,
        )));
    }

    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        $provider = $this->providers[$panel->providerKey] ?? null;
        $descriptor = $this->descriptors[$panel->providerKey] ?? null;

        if ($provider === null || $descriptor === null || ! $this->providerActive(descriptor: $descriptor, context: $context)) {
            return PanelAccess::deny('provider_unavailable');
        }

        if ($panel->parent !== null) {
            $parent = $this->activeEntity(context: $context, entity: $panel->parent);

            if ($parent === null || ! $this->supports(descriptor: $descriptor, capabilities: $parent->capabilities)) {
                return PanelAccess::deny('entity_unavailable');
            }
        }

        try {
            $access = $provider->access($context, $panel);
        } catch (Throwable) {
            return PanelAccess::deny('policy_unavailable');
        }

        $supported = $descriptor->supportedActions;

        if (array_diff($access->actions, $supported) !== []) {
            return PanelAccess::deny('invalid_policy_result');
        }

        return $access;
    }

    /** @param list<Capability> $capabilities */
    private function visibleDescriptor(PanelDescriptor $descriptor, PanelContext $context, array $capabilities): ?PanelDescriptor
    {
        if (! $this->providerActive(descriptor: $descriptor, context: $context)) {
            return null;
        }

        return $this->supports(descriptor: $descriptor, capabilities: $capabilities) ? $descriptor : null;
    }

    private function providerActive(PanelDescriptor $descriptor, PanelContext $context): bool
    {
        return $this->modules->isActive(name: $descriptor->owningModule, ctx: $context->tenant);
    }

    /** @param list<Capability> $capabilities */
    private function supports(PanelDescriptor $descriptor, array $capabilities): bool
    {
        if ($descriptor->requiredCapability === null) {
            return true;
        }

        $capability = Capability::tryFrom($descriptor->requiredCapability);

        return $capability !== null && in_array($capability, $capabilities, true);
    }

    private function activeEntity(PanelContext $context, EntityReference $entity): ?EntityDefinition
    {
        if (! $this->entities->has($entity->handle)) {
            return null;
        }

        $definition = $this->entities->get($entity->handle);

        return in_array($definition, $this->entities->all($context->tenant), true) ? $definition : null;
    }
}
