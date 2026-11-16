<?php

declare(strict_types=1);

namespace Packman\Extensions\Concerns\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Contracts\Runtime\WithLifecycleHooks;

/**
 * Default implementation for {@see WithLifecycleHooks}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait HasLifecycleHooks
{
    /** @return class-string|null */
    public function onEnableHook(): ?string
    {
        return null;
    }

    /** @return class-string|null */
    public function onDisableHook(): ?string
    {
        return null;
    }

    /** @return class-string|null */
    public function onPurgeHook(): ?string
    {
        return null;
    }

    /** @return class-string|null */
    public function removalGuard(): ?string
    {
        return null;
    }
}
