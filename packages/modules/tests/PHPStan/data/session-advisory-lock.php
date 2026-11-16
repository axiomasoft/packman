<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan\Data\SessionAdvisoryLock;

use Illuminate\Support\Facades\DB;

final class SessionAdvisoryLockFixture
{
    public function violation(): void
    {
        DB::statement('select pg_advisory_lock(hashtext(?))', ['modules']);
    }

    public function legal(): void
    {
        DB::statement('select pg_advisory_xact_lock(hashtext(?))', ['modules']);
    }
}
