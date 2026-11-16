<?php

declare(strict_types=1);

namespace Packman\Extensions\Contracts\Composition;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithPanels
{
    /** @return list<class-string> */
    public function panelProviders(): array;
}
