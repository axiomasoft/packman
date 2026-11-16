<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan\Data\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DirectSystemTableWriteFixture
{
    public function violation(): void
    {
        DB::table('sys_settings')->insert(['key' => 'billing.plan', 'value' => 'pro']);
    }

    public function schemaViolation(): void
    {
        Schema::drop('mod_records');
    }

    public function rawSqlViolation(): void
    {
        DB::statement('delete from sys_audit_log where id = ?', ['x']);
    }

    public function legalRead(): void
    {
        DB::table('sys_settings')->get();
    }

    public function legalOwnTable(): void
    {
        DB::table('bil_invoices')->insert(['total' => 100]);
    }
}
