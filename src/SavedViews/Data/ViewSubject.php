<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ViewSubject
{
    public function __construct(public ViewScope $scope, public ?string $viewId) {}
}
