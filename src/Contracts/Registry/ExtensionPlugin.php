<?php

declare(strict_types=1);

namespace Packman\Plugins\Contracts\Registry;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Pure declarative unit of behavior: zero constructor arguments, zero I/O, zero database, zero container reads.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ExtensionPlugin
{
    public function manifest(): PluginManifest;

    public function register(PluginRegistrar $registrar): void;
}
