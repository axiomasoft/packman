<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs;

use Illuminate\Database\Events\MigrationStarted;

final class LifecycleStringMigrationListener
{
    public static int $calls = 0;

    public function onStarted(MigrationStarted $event): void
    {
        self::$calls++;
    }
}
