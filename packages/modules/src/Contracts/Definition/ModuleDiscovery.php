<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts\Definition;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Data\Lifecycle\ModuleManifest;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ModuleDiscovery
{
    /** @return list<ModuleManifest> */
    public function discover(): array;
}
