<?php

declare(strict_types=1);

namespace Packman\Plugins\Contracts\Contributions;

use Packman\Attributes\Api;
use Packman\Plugins\Data\Capabilities\CapabilityDefinition;
use Packman\Plugins\Data\Capabilities\CapabilityProvider;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Contributions\SlotContribution;
use Packman\Plugins\Data\Contributions\SlotDefinition;

/** Declarations made by a single plugin during register(). */
#[Api(since: '0.1.1')]
interface DeclaredContributions
{
    /** @return list<ContributionKind> */
    public function kinds(): array;

    /** @return list<array{kind: string, contribution: object|class-string}> */
    public function contributions(): array;

    /** @return list<CapabilityDefinition> */
    public function capabilityDefinitions(): array;

    /** @return list<CapabilityProvider> */
    public function capabilityProviders(): array;

    /** @return list<SlotDefinition> */
    public function slotDefinitions(): array;

    /** @return list<SlotContribution> */
    public function slotContributions(): array;
}
