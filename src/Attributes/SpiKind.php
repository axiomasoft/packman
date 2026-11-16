<?php

declare(strict_types=1);

namespace Packman\Attributes;

#[Api(since: '1.0')]
enum SpiKind: string
{
    case Implement = 'implement';
    case Call = 'call';
}
