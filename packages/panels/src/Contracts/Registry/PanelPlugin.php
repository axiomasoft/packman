<?php

declare(strict_types=1);

namespace Packman\Panels\Contracts\Registry;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Data\Panel\PanelPluginContext;

/**
 * A ready set of additions to a compiled panel: slots, permissions, roles, restrictions, and options.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PanelPlugin
{
    public function id(): string;

    public function register(PanelBuilder $panel, PanelPluginContext $context): void;
}
