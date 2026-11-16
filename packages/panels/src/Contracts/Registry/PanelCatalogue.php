<?php

declare(strict_types=1);

namespace Packman\Panels\Contracts\Registry;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;
use Packman\Panels\Data\Subjects\EntityReference;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PanelCatalogue
{
    /** @return list<PanelDescriptor> */
    public function forEntity(PanelContext $context, EntityReference $entity): array;

    public function access(PanelContext $context, PanelReference $panel): PanelAccess;
}
