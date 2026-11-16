<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan\Data\SqlPrepare;

use Illuminate\Support\Facades\DB;

final class SqlPrepareFixture
{
    public function violation(): void
    {
        DB::statement('PREPARE fetch_widget (uuid) AS select * from widgets where id = $1');
    }

    public function legal(): void
    {
        DB::select('select * from widgets where id = ?', ['a']);
    }
}
