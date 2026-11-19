<?php

declare(strict_types=1);

namespace Packman\Plugins\Laravel;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Contributions\DeclaredContributions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Capabilities\CapabilityDefinition;
use Packman\Plugins\Data\Capabilities\CapabilityProvider;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Contributions\SlotContribution;
use Packman\Plugins\Data\Contributions\SlotDefinition;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Exceptions\Registry\RegistryFrozenException;
use Packman\Plugins\Internal\Graph\PluginDeclarations;

/**
 * Passed to `ExtensionPlugin::register()`. Records pure declarations and seals when `register()` returns.
 */
#[Api(since: '1.0')]
final readonly class PluginRegistrar
{
    /** @internal created by the plugin graph compiler */
    #[Internal('Constructed by the package registrar.')]
    public function __construct(private PluginDeclarations $declarations, public string $pluginId) {}

    public static function collect(ExtensionPlugin $plugin): DeclaredContributions
    {
        return PluginDeclarations::record($plugin, $plugin->manifest()->id);
    }

    public function declareKind(ContributionKind $kind): void
    {
        if ($kind->ownerPluginId !== $this->pluginId) {
            throw new InvalidConfiguration(["plugin.kind.owner: plugin '{$this->pluginId}' declared kind '{$kind->name}' for '{$kind->ownerPluginId}'"]);
        }

        $this->guard();
        $this->declarations->addKind($kind);
    }

    /** @param object|class-string $contribution */
    public function contribute(string $kind, object|string $contribution): void
    {
        $this->guard();
        $this->declarations->addContribution($kind, $contribution);
    }

    /** @param class-string $contract */
    public function defineCapability(string $contract, Cardinality $cardinality = Cardinality::RequiredSingle): self
    {
        $this->guard();
        $this->declarations->addCapabilityDefinition(new CapabilityDefinition($contract, $this->pluginId, $cardinality));

        return $this;
    }

    /**
     * @param  class-string  $contract
     * @param  class-string  $implementation
     */
    public function provides(string $contract, string $implementation): self
    {
        $this->guard();
        $this->declarations->addCapabilityProvider(CapabilityProvider::fromClass($contract, $this->pluginId, $implementation));

        return $this;
    }

    /**
     * @param  class-string  $contract
     * @param  class-string|null  $default
     */
    public function providesFromConfig(string $contract, string $configKey, ?string $default = null): self
    {
        $this->guard();
        $this->declarations->addCapabilityProvider(CapabilityProvider::providesFromConfig($contract, $this->pluginId, $configKey, $default));

        return $this;
    }

    /** @param class-string $contract */
    public function defineSlot(string $contract, SlotKind $kind, Cardinality $cardinality = Cardinality::Many, bool $shared = false): self
    {
        $this->guard();
        $this->declarations->addSlotDefinition(new SlotDefinition($contract, $this->pluginId, $kind, $cardinality, $shared));

        return $this;
    }

    /**
     * @param  class-string  $slot
     * @param  class-string  $implementation
     */
    public function contributeSlot(string $slot, string $id, string $implementation, int $priority = 0, ?string $key = null): self
    {
        $this->guard();
        $this->declarations->addSlotContribution(new SlotContribution($slot, $id, $this->pluginId, $implementation, $priority, $key));

        return $this;
    }

    private function guard(): void
    {
        if ($this->declarations->sealed()) {
            throw RegistryFrozenException::forRegistry("plugin '{$this->pluginId}'");
        }
    }
}
