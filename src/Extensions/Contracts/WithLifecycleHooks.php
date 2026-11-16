<?php

declare(strict_types=1);

namespace Packman\Extensions\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithLifecycleHooks
{
    /** @return class-string|null */
    public function onEnableHook(): ?string;

    /** @return class-string|null */
    public function onDisableHook(): ?string;

    /** @return class-string|null */
    public function onPurgeHook(): ?string;

    /** @return class-string|null */
    public function removalGuard(): ?string;
}
