<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan\Data\TempTable;

use Illuminate\Support\Facades\DB;

final class TempTableFixture
{
    public function violation(): void
    {
        DB::statement('CREATE TEMP TABLE import_buffer (id int)');
    }

    public function legal(): void
    {
        DB::statement('CREATE TABLE crm_import_buffer (id int)');
    }
}
