<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ViewField
{
    /** @param list<string> $operators */
    public function __construct(
        public string $key,
        public string $type,
        public array $operators,
        public bool $visible,
        public bool $filterable,
        public bool $sortable,
        public bool $projectable,
        public string $mappingKey,
    ) {}
}
