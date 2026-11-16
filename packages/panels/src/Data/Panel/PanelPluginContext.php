<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Panel;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Scoped build context passed to a panel plugin during compilation.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class PanelPluginContext
{
    /**
     * @internal built by the panel compiler
     */
    public function __construct(
        private string $panelId,
        private string $pluginId,
        private string $buildId,
    ) {}

    public function panelId(): string
    {
        return $this->panelId;
    }

    public function pluginId(): string
    {
        return $this->pluginId;
    }

    public function buildId(): string
    {
        return $this->buildId;
    }
}
