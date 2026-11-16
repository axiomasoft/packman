<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Graph;

use Packman\Plugins\Data\Capabilities\CapabilityDefinition;
use Packman\Plugins\Data\Capabilities\CapabilityProvider;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Contributions\SlotContribution;
use Packman\Plugins\Data\Contributions\SlotDefinition;

/** What one plugin declared inside `register()`; sealed immediately afterwards. */
final class PluginDeclarations
{
    /** @var list<ContributionKind> */
    private array $kinds = [];

    /** @var list<array{kind: string, contribution: object|class-string}> */
    private array $contributions = [];

    /** @var list<CapabilityDefinition> */
    private array $capabilityDefinitions = [];

    /** @var list<CapabilityProvider> */
    private array $capabilityProviders = [];

    /** @var list<SlotDefinition> */
    private array $slotDefinitions = [];

    /** @var list<SlotContribution> */
    private array $slotContributions = [];

    private bool $sealed = false;

    public function addKind(ContributionKind $kind): void
    {
        $this->kinds[] = $kind;
    }

    /** @param object|class-string $contribution */
    public function addContribution(string $kind, object|string $contribution): void
    {
        $this->contributions[] = ['kind' => $kind, 'contribution' => $contribution];
    }

    public function addCapabilityDefinition(CapabilityDefinition $definition): void
    {
        $this->capabilityDefinitions[] = $definition;
    }

    public function addCapabilityProvider(CapabilityProvider $provider): void
    {
        $this->capabilityProviders[] = $provider;
    }

    public function addSlotDefinition(SlotDefinition $definition): void
    {
        $this->slotDefinitions[] = $definition;
    }

    public function addSlotContribution(SlotContribution $contribution): void
    {
        $this->slotContributions[] = $contribution;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function sealed(): bool
    {
        return $this->sealed;
    }

    /** @return list<ContributionKind> */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /** @return list<array{kind: string, contribution: object|class-string}> */
    public function contributions(): array
    {
        return $this->contributions;
    }

    /** @return list<CapabilityDefinition> */
    public function capabilityDefinitions(): array
    {
        return $this->capabilityDefinitions;
    }

    /** @return list<CapabilityProvider> */
    public function capabilityProviders(): array
    {
        return $this->capabilityProviders;
    }

    /** @return list<SlotDefinition> */
    public function slotDefinitions(): array
    {
        return $this->slotDefinitions;
    }

    /** @return list<SlotContribution> */
    public function slotContributions(): array
    {
        return $this->slotContributions;
    }
}
