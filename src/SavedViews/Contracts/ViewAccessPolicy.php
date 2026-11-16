<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\SavedViews\Data\ViewAction;
use Packman\Panels\SavedViews\Data\ViewContext;
use Packman\Panels\SavedViews\Data\ViewSubject;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ViewAccessPolicy
{
    public function allows(ViewContext $context, ViewAction $action, ViewSubject $subject): bool;
}
