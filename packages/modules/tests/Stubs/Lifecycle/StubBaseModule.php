<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs\Lifecycle;

use Packman\Modules\Contracts\ModuleDefinition;
use Packman\Modules\Data\ModuleManifest;

/**
 * No-dependency module used across ModuleLifecycleTest: two migrations
 * (claiming their tables via RecordsRegistrar) + idempotent hooks — enough
 * to exercise enable/disable/archive/purge (AC-5, AC-8..AC-11).
 */
final class StubBaseModule extends ModuleDefinition
{
    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('packman/stub-base')
            ->title('Stub Base')
            ->tablePrefix('sba')
            ->onEnable(StubEnableHook::class)
            ->onDisable(StubDisableHook::class)
            ->onPurge(StubPurgeHook::class);
    }
}
