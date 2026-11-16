<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs\Lifecycle;

use Packman\Modules\Contracts\Lifecycle\DisableHook;
use Packman\Modules\Data\Lifecycle\ModuleRuntimeContext;

/** No data side effects (B-10 §7.4 п.1) — nothing to tear down here. */
final class StubDisableHook implements DisableHook
{
    public function __invoke(ModuleRuntimeContext $ctx): void {}
}
