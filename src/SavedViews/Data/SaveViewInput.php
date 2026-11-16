<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Data;

use Packman\Attributes\Api;
use Packman\Data\Data\JsonDocument;

#[Api(since: '1.0')]
final readonly class SaveViewInput
{
    /**
     * @param  array<string, string>  $localizedName
     */
    public function __construct(
        public ?string $id,
        public string $expectedRevision,
        public ViewScope $scope,
        public string $kind,
        public array $localizedName,
        public JsonDocument $config,
    ) {}
}
