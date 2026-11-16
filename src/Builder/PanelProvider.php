<?php

declare(strict_types=1);

namespace Packman\Panels\Builder;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelRegistry;

/**
 * Describes one compiled panel of the application; registered by a host ServiceProvider or `WithPanels` extension.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class PanelProvider extends ServiceProvider
{
    /**
     * Stable id of the panel; a class or namespace name is never turned into an id.
     */
    abstract public static function getId(): string;

    abstract public function panel(PanelBuilder $panel): PanelBuilder;

    #[Override]
    public function register(): void
    {
        $this->app->make(PanelRegistry::class)->register(static::class);
    }
}
