<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs\Lifecycle;

use Packman\Modules\Contracts\Lifecycle\PurgeHook;
use Packman\Modules\Data\Lifecycle\ModuleRuntimeContext;

/** No non-standard tail to clean up — table/setting teardown is `mod_records`-driven. */
final class StubPurgeHook implements PurgeHook
{
    public function __invoke(ModuleRuntimeContext $ctx): void {}
}
