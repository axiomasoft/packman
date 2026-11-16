<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan\Data\ListenNotify;

use Illuminate\Support\Facades\DB;

final class ListenNotifyFixture
{
    public function violation(): void
    {
        DB::unprepared('LISTEN module_events');
    }

    public function alsoViolation(): void
    {
        DB::statement("select pg_notify('module_events', ?)", ['payload']);
    }

    public function legal(): void
    {
        DB::table('mod_records')->insert(['reference' => 'a']);
    }
}
