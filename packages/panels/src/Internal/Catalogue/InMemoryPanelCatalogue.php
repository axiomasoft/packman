<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Catalogue;

use LogicException;
use Packman\Attributes\Internal;
use Packman\Panels\Contracts\Registry\PanelCatalogue;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;
use Packman\Panels\Data\Subjects\EntityReference;
use Throwable;

/**
 * Standalone panel catalogue used when `packman-modules` is not installed or bound.
 */
#[Internal]
final class InMemoryPanelCatalogue implements PanelCatalogue
{
    /** @var array<string, PanelProvider> */
    private array $providers = [];

    /** @var array<string, PanelDescriptor> */
    private array $descriptors = [];

    /** @param iterable<PanelProvider> $providers */
    public function __construct(iterable $providers)
    {
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
        return array_values($this->descriptors);
    }

    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        $provider = $this->providers[$panel->providerKey] ?? null;
        $descriptor = $this->descriptors[$panel->providerKey] ?? null;

        if ($provider === null || $descriptor === null) {
            return PanelAccess::deny('provider_unavailable');
        }

        try {
            $access = $provider->access($context, $panel);
        } catch (Throwable) {
            return PanelAccess::deny('policy_unavailable');
        }

        if (array_diff($access->actions, $descriptor->supportedActions) !== []) {
            return PanelAccess::deny('invalid_policy_result');
        }

        return $access;
    }
}
