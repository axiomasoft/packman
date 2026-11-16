<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Internal;

use Packman\Attributes\Internal;
use Packman\Panels\SavedViews\Contracts\ViewAccessPolicy;
use Packman\Panels\SavedViews\Data\ViewAction;
use Packman\Panels\SavedViews\Data\ViewContext;
use Packman\Panels\SavedViews\Data\ViewSubject;

#[Internal]
final class DenyViewAccessPolicy implements ViewAccessPolicy
{
    public function allows(ViewContext $context, ViewAction $action, ViewSubject $subject): bool
    {
        return false;
    }
}
