<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ViewSchema
{
    /** @param list<ViewField> $fields */
    public function __construct(
        public string $version,
        public string $entityHandle,
        public array $fields,
    ) {}
}
