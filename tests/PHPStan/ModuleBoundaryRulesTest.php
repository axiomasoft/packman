<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan;

use Packman\Modules\PHPStan\Rules\Boundaries\NoDirectSystemTableWriteRule;
use Packman\Modules\PHPStan\Rules\Boundaries\NoForeignPrefixSqlRule;
use Packman\Modules\PHPStan\Rules\Boundaries\NoFsOutsideModuleRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Контекстные правила границ модуля (B-10 §7.4 п.9 + fs): владение таблицами и
 * директорией берётся из конфига neon, поэтому код вне карты правила не трогают.
 *
 * @extends RuleTestCase<Rule>
 */
final class ModuleBoundaryRulesTest extends RuleTestCase
{
    private const string MODULE_NAMESPACE = 'Packman\Modules\Tests\PHPStan\Data\Billing\\';

    private Rule $rule;

    protected function getRule(): Rule
    {
        return $this->rule;
    }

    public function test_foreign_prefix_sql(): void
    {
        $this->rule = new NoForeignPrefixSqlRule([
            self::MODULE_NAMESPACE => 'bil',
            'Packman\Modules\Tests\PHPStan\Data\Crm\\' => 'crm',
        ]);

        $this->analyse([__DIR__.'/data/foreign-prefix.php'], [
            [
                'Direct DB access to table "crm_contacts" of a foreign prefix (module owns "bil_") is forbidden (B-10 §7.4 п.9) — go through the owner\'s contract.',
                13,
            ],
            [
                'Direct DB access to table "sys_settings" of a foreign prefix (module owns "bil_") is forbidden (B-10 §7.4 п.9) — go through the owner\'s contract.',
                18,
            ],
        ]);
    }

    public function test_foreign_prefix_sql_no_false_positive(): void
    {
        $this->rule = new NoForeignPrefixSqlRule([
            self::MODULE_NAMESPACE => 'bil',
            'Packman\Modules\Tests\PHPStan\Data\Crm\\' => 'crm',
        ]);

        $this->analyse([__DIR__.'/data/foreign-prefix-sql.php'], [
            [
                'Direct DB access to table "mod_records" of a foreign prefix (module owns "bil_") is forbidden (B-10 §7.4 п.9) — go through the owner\'s contract.',
                39,
            ],
        ]);
    }

    public function test_fs_outside_module(): void
    {
        $this->rule = new NoFsOutsideModuleRule([self::MODULE_NAMESPACE]);

        $this->analyse([__DIR__.'/data/fs-outside-module.php'], [
            [
                'Filesystem access outside the module directory is forbidden (B-10 §7.3 enforcement): "/etc/flex/invoice.log".',
                11,
            ],
            [
                'Filesystem access outside the module directory is forbidden (B-10 §7.3 enforcement): "../../.env".',
                16,
            ],
        ]);
    }

    public function test_direct_system_table_write(): void
    {
        $this->rule = new NoDirectSystemTableWriteRule(['Acme\Core\\']);

        $this->analyse([__DIR__.'/data/direct-system-table-write.php'], [
            [
                'Direct write to core table "sys_settings" is forbidden (B-10 §7.4 п.9) — use the owning core contract.',
                14,
            ],
            [
                'Direct write to core table "mod_records" is forbidden (B-10 §7.4 п.9) — use the owning core contract.',
                19,
            ],
            [
                'Direct write to core table "sys_audit_log" is forbidden (B-10 §7.4 п.9) — use the owning core contract.',
                24,
            ],
        ]);
    }
}
