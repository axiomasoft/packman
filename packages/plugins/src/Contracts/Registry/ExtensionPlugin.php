<?php

declare(strict_types=1);

namespace Packman\Plugins\Contracts\Registry;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Discovered through a zero-argument instance and its manifest. An active instance may carry
 * host-supplied values. Its manifest stays constant and its declarations perform no I/O.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ExtensionPlugin
{
    public function manifest(): PluginManifest;

    public function register(PluginRegistrar $registrar): void;
}
