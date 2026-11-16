<?php

declare(strict_types=1);

namespace Packman\Plugins\Concerns;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Plugins\Contracts\ExtensionPlugin;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Default no-op `register()` for {@see ExtensionPlugin} classes that declare all facets declaratively via `With*` interfaces.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait RegistersNothing
{
    public function register(PluginRegistrar $registrar): void {}
}
