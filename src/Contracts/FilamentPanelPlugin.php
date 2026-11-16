<?php

declare(strict_types=1);

namespace Packman\Filament\Contracts;

use Filament\Contracts\Plugin;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Filament panel plugin contract exposing enforcement, guard-panel binding, exclusions, and governed resource abilities.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface FilamentPanelPlugin extends Plugin
{
    public function getGuardPanel(): ?string;

    public function isEnforced(): bool;

    /**
     * @return array{resources: list<string>, pages: list<string>, widgets: list<string>}
     */
    public function getExclude(): array;

    /**
     * @return list<string>
     */
    public function getAbilities(): array;
}
