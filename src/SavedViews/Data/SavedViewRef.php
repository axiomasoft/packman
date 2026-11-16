<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class SavedViewRef
{
    public function __construct(
        public string $id,
        public ViewScope $scope,
        public string $kind,
        public string $revision,
        public bool $isDefault,
    ) {}
}
