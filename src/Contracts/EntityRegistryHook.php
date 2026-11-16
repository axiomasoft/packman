<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Extension hook invoked after module entities and MorphMap are projected at boot.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface EntityRegistryHook
{
    public function wire(EntityRegistry $registry): void;
}
