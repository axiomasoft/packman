<?php

declare(strict_types=1);

namespace Packman\Extensions\Concerns;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Contracts\WithDiagnostics;

/**
 * Default implementation for {@see WithDiagnostics}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait ProvidesDiagnostics
{
    /** @return list<class-string> */
    public function healthChecks(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function problemProviders(): array
    {
        return [];
    }
}
