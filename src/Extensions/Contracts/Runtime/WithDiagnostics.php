<?php

declare(strict_types=1);

namespace Packman\Extensions\Contracts\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithDiagnostics
{
    /** @return list<class-string> */
    public function healthChecks(): array;

    /** @return list<class-string> */
    public function problemProviders(): array;
}
