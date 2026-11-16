<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Contracts;

use Packman\Attributes\Api;
use Packman\Panels\SavedViews\Data\SavedQuery;
use Packman\Panels\SavedViews\Data\SavedViewRef;
use Packman\Panels\SavedViews\Data\SaveViewInput;
use Packman\Panels\SavedViews\Data\ViewContext;
use Packman\Panels\SavedViews\Data\ViewScope;

#[Api(since: '1.0')]
interface SavedViewRepository
{
    /** @return list<SavedViewRef> */
    public function viewsFor(ViewContext $context, ViewScope $scope): array;

    public function save(ViewContext $context, SaveViewInput $input): SavedViewRef;

    public function delete(ViewContext $context, string $viewId): void;

    public function setDefault(ViewContext $context, string $viewId): SavedViewRef;

    public function compile(ViewContext $context, string $viewId): SavedQuery;
}
