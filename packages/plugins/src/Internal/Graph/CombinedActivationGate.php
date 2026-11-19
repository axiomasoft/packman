<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Graph;

use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;

#[Internal]
final readonly class CombinedActivationGate implements PluginActivationGate
{
    /** @param list<PluginActivationGate> $gates */
    public function __construct(private array $gates) {}

    public function isEnabled(string $pluginId): bool
    {
        return array_all($this->gates, static fn (PluginActivationGate $gate): bool => $gate->isEnabled($pluginId));
    }
}
