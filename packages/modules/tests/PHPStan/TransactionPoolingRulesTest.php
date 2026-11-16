<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan;

use Packman\Modules\PHPStan\Rules\NoEvalRule;
use Packman\Modules\PHPStan\Rules\NoListenNotifyRule;
use Packman\Modules\PHPStan\Rules\NoSessionAdvisoryLockRule;
use Packman\Modules\PHPStan\Rules\NoSetWithoutLocalRule;
use Packman\Modules\PHPStan\Rules\NoSqlPrepareRule;
use Packman\Modules\PHPStan\Rules\NoTempTableRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Простые правила transaction-pooling дисциплины (B-10 §7.3 п.1–5 + eval):
 * позитив ловится, легальный код молчит.
 *
 * @extends RuleTestCase<Rule>
 */
final class TransactionPoolingRulesTest extends RuleTestCase
{
    private Rule $rule;

    protected function getRule(): Rule
    {
        return $this->rule;
    }

    public function test_set_without_local(): void
    {
        $this->rule = new NoSetWithoutLocalRule;

        $this->analyse([__DIR__.'/data/set-without-local.php'], [
            [
                'Session-level SET is forbidden under transaction pooling (B-10 §7.3 п.1) — use SET LOCAL inside a transaction.',
                13,
            ],
        ]);
    }

    public function test_session_advisory_lock(): void
    {
        $this->rule = new NoSessionAdvisoryLockRule;

        $this->analyse([__DIR__.'/data/session-advisory-lock.php'], [
            [
                'Session-level pg_advisory_lock/unlock is forbidden under transaction pooling (B-10 §7.3 п.3) — use pg_advisory_xact_lock.',
                13,
            ],
        ]);
    }

    public function test_listen_notify(): void
    {
        $this->rule = new NoListenNotifyRule;

        $this->analyse([__DIR__.'/data/listen-notify.php'], [
            [
                'LISTEN/NOTIFY is forbidden under transaction pooling (B-10 §7.3 п.4) — publish through the PubSub contract.',
                13,
            ],
            [
                'LISTEN/NOTIFY is forbidden under transaction pooling (B-10 §7.3 п.4) — publish through the PubSub contract.',
                18,
            ],
        ]);
    }

    public function test_temp_table(): void
    {
        $this->rule = new NoTempTableRule;

        $this->analyse([__DIR__.'/data/temp-table.php'], [
            [
                'Temporary tables are forbidden under transaction pooling (B-10 §7.3 п.2).',
                13,
            ],
        ]);
    }

    public function test_sql_prepare(): void
    {
        $this->rule = new NoSqlPrepareRule;

        $this->analyse([__DIR__.'/data/sql-prepare.php'], [
            [
                'SQL-level PREPARE/DEALLOCATE is forbidden under transaction pooling (B-10 §7.3 п.5).',
                13,
            ],
        ]);
    }

    public function test_eval(): void
    {
        $this->rule = new NoEvalRule;

        $this->analyse([__DIR__.'/data/eval.php'], [
            [
                'eval() is forbidden in module code (B-10 §7.3 enforcement list) — it escapes static analysis and marketplace review.',
                11,
            ],
        ]);
    }
}
