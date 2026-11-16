<?php

declare(strict_types=1);

namespace Packman\Config\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface RuntimeConfigCheck
{
    /** @return list<string> "<config path>: <message>"; empty when the process may accept work */
    public function violations(bool $production): array;
}
