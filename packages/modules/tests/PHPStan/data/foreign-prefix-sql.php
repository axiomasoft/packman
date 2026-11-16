<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan\Data\Billing;

use Illuminate\Support\Facades\DB;

/**
 * A35 (findings/P1-audit-2026-07-13.md): негативные фикстуры правила 6 —
 * чужой префикс детектится ТОЛЬКО на позиции имени таблицы, не на любом
 * вхождении `xx_yyy` в SQL-литерале (колонка/алиас/строковое значение).
 */
final class ForeignPrefixSqlFixture
{
    /**
     * A35 repro: строковое значение 'mod_pending' похоже на чужой префикс,
     * но это ЗНАЧЕНИЕ колонки status, а не таблица — легально.
     */
    public function stringValueLookingLikeForeignPrefix(): void
    {
        DB::select("select * from bil_invoices where status = 'mod_pending'");
    }

    /**
     * Колонка `mod_id` — не таблица `mod_*`, легально.
     */
    public function columnNameLookingLikeForeignPrefix(): void
    {
        DB::select('select * from bil_invoices where mod_id = ?', [5]);
    }

    /**
     * Позиция после FROM внутри подзапроса всё ещё детектится — правило не
     * ослаблено, а сужено до позиции имени таблицы.
     */
    public function foreignTableInSubqueryIsStillCaught(): void
    {
        DB::statement('UPDATE bil_invoices SET total = 0 WHERE id IN (SELECT id FROM mod_records)');
    }
}
