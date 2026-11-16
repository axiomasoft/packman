<?php

declare(strict_types=1);

namespace Packman\Extensions\Concerns;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Contracts\WithCapabilities;

/**
 * Default implementation for {@see WithCapabilities}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait ProvidesCapabilities
{
    /** @return array<class-string, string> */
    public function definedCapabilities(): array
    {
        return [];
    }

    /** @return array<class-string, class-string|array{config: string, default?: class-string|null}> */
    public function providedCapabilities(): array
    {
        return [];
    }
}
