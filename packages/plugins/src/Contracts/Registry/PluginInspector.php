<?php

declare(strict_types=1);

namespace Packman\Plugins\Contracts\Registry;

use Packman\Attributes\Api;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Plugins\Data\Contributions\SlotContribution;
use Packman\Plugins\Data\Contributions\SlotDefinition;
use Packman\Plugins\Data\Plugin\PluginDescription;

/** Read-only view of the compiled plugin registry for diagnostics (`packman:plugins`). */
#[Api(since: '1.0')]
interface PluginInspector
{
    /** @return list<PluginDescription> plugins in load order */
    public function describe(): array;

    /** @return array<class-string, SlotDefinition> */
    public function slots(): array;

    /** @return array<class-string, list<SlotContribution>> */
    public function slotContributions(): array;

    /** @return list<string> */
    public function disabledIds(): array;

    public function facetsOf(string $pluginId): ?ExtensionFacetProfile;
}
