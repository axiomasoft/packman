<?php

declare(strict_types=1);

namespace Packman\Attributes;

use Attribute;

#[Api(since: '1.0')]
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Spi
{
    public function __construct(public SpiKind $kind, public string $since = '1.0') {}
}
