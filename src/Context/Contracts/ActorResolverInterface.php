<?php

declare(strict_types=1);

namespace Packman\Context\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Context\Data\ActorRef;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ActorResolverInterface
{
    public function current(): ActorRef;
}
