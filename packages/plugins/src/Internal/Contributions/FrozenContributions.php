<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Contributions;

use Packman\Attributes\Internal;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Data\Capabilities\CapabilityDefinition;
use Packman\Plugins\Data\Capabilities\CapabilityProvider;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Contributions\SlotContribution;
use Packman\Plugins\Data\Contributions\SlotDefinition;
use Packman\Plugins\Data\Plugin\PluginDescription;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

/** The compiled, frozen plugin and extension registry. Built once by PluginGraphCompiler. */
#[Internal]
final readonly class FrozenContributions implements Contributions, PluginInspector
{
    /**
     * @param  array<string, ContributionKind>  $kinds
     * @param  array<string, array<string, object>>  $items  kind => key => contribution, in load order
     * @param  list<PluginDescription>  $plugins
     * @param  array<class-string, CapabilityDefinition>  $capabilities
     * @param  array<class-string, list<CapabilityProvider>>  $providers
     * @param  array<class-string, SlotDefinition>  $slots
     * @param  array<class-string, list<SlotContribution>>  $slotContributions
     * @param  list<string>  $disabled
     * @param  array<string, ExtensionFacetProfile>  $facetsByPlugin
     */
    public function __construct(
        private array $kinds,
        private array $items,
        private array $plugins,
        private array $capabilities = [],
        private array $providers = [],
        private array $slots = [],
        private array $slotContributions = [],
        private array $disabled = [],
        private array $facetsByPlugin = [],
    ) {}

    public function get(string $kind, string $key, string $interface): object
    {
        if (! isset($this->kinds[$kind])) {
            throw new UnsupportedContribution($kind, $key, 'no installed plugin declares this contribution kind');
        }

        $contribution = $this->items[$kind][$key] ?? throw new UnsupportedContribution($kind, $key, 'no registered plugin contributes this key');

        if (! $contribution instanceof $interface) {
            throw new UnsupportedContribution($kind, $key, "the contribution does not implement {$interface}");
        }

        return $contribution;
    }

    public function has(string $kind, string $key): bool
    {
        return isset($this->items[$kind][$key]);
    }

    public function keys(string $kind): array
    {
        return array_keys($this->items[$kind] ?? []);
    }

    public function describe(): array
    {
        return $this->plugins;
    }

    /** @return array<class-string, CapabilityDefinition> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /** @return array<class-string, list<CapabilityProvider>> */
    public function providers(): array
    {
        return $this->providers;
    }

    /** @return array<class-string, SlotDefinition> */
    public function slots(): array
    {
        return $this->slots;
    }

    /** @return array<class-string, list<SlotContribution>> */
    public function slotContributions(): array
    {
        return $this->slotContributions;
    }

    /** @return list<string> */
    public function disabledIds(): array
    {
        return $this->disabled;
    }

    public function facetsOf(string $pluginId): ?ExtensionFacetProfile
    {
        return $this->facetsByPlugin[$pluginId] ?? null;
    }

    /** @return list<string> */
    public function databaseRegisteredPluginIds(): array
    {
        $ids = [];

        foreach ($this->facetsByPlugin as $id => $profile) {
            if ($profile->persistsInDatabase) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
