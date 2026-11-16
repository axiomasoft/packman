<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan\Data\SetWithoutLocal;

use Illuminate\Support\Facades\DB;

final class SetWithoutLocalFixture
{
    public function violation(): void
    {
        DB::statement('SET statement_timeout = 5000');
    }

    public function legal(): void
    {
        DB::statement('SET LOCAL statement_timeout = 5000');
        DB::statement('update widgets set name = ? where id = ?', ['a', 'b']);
    }
}
