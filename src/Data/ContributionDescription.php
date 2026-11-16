<?php

declare(strict_types=1);

namespace Packman\Plugins\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ContributionDescription
{
    /** @param class-string $class */
    public function __construct(public string $kind, public string $key, public string $class) {}
}
