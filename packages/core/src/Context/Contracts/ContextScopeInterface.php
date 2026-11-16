<?php

declare(strict_types=1);

namespace Packman\Context\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ContextScopeInterface
{
    public function id(): string;
}
