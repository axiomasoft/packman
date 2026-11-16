<?php

declare(strict_types=1);

namespace Packman\Plugins\Contracts\Registry;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Optional activation gate for platforms that store plugin/module activation state in a database
 * (e.g. modular CMS-style hosts). Standalone code plugins operate purely at compile time without this gate.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PluginActivationGate
{
    public function isEnabled(string $pluginId): bool;
}
