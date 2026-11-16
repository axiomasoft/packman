<?php

declare(strict_types=1);

namespace Packman\Schema\Enums;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
enum SchemaLifecycle: string
{
    case Platform = 'platform';
    case Module = 'module';
}
