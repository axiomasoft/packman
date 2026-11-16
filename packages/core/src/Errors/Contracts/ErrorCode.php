<?php

declare(strict_types=1);

namespace Packman\Errors\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ErrorCode
{
    public function value(): string;
}
