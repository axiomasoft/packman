<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Data;

use Packman\Attributes\Api;
use Packman\Data\Data\JsonDocument;

#[Api(since: '1.0')]
final readonly class SavedQuery
{
    /**
     * @param  list<array{field: string, direction: string}>  $sort
     * @param  list<string>  $projection
     */
    public function __construct(
        public string $schemaVersion,
        public JsonDocument $filters,
        public array $sort,
        public array $projection,
        public ?string $groupBy,
    ) {}
}
