<?php

declare(strict_types=1);

namespace Packman\Extensions\Concerns;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Contracts\WithPanels;

/**
 * Default implementation for {@see WithPanels}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait ProvidesPanels
{
    /** @return list<class-string> */
    public function panelProviders(): array
    {
        return [];
    }
}
