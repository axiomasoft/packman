<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan\Data\Billing;

use Illuminate\Support\Facades\DB;

final class ForeignPrefixFixture
{
    public function violation(): void
    {
        DB::table('crm_contacts')->get();
    }

    public function coreTableViolation(): void
    {
        DB::select('select * from sys_settings where key = ?', ['x']);
    }

    public function legal(): void
    {
        DB::table('bil_invoices')->get();
    }
}
