<?php

declare(strict_types=1);

namespace Packman\Errors\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Data\ErrorDescriptor;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DescribesError
{
    public function errorDescriptor(): ErrorDescriptor;
}
