<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts\Lifecycle;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Data\Lifecycle\ModuleRuntimeContext;

/**
 * Module-authored teardown of non-data side effects on disable (cron,
 * webhooks). MUST NOT touch the module's data.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DisableHook
{
    public function __invoke(ModuleRuntimeContext $ctx): void;
}
