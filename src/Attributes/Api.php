<?php

declare(strict_types=1);

namespace Packman\Attributes;

use Attribute;

#[Api(since: '1.0')]
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Api
{
    public function __construct(public string $since = '1.0') {}
}
