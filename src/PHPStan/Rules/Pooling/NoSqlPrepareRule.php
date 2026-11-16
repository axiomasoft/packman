<?php

declare(strict_types=1);

namespace Packman\Modules\PHPStan\Rules\Pooling;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * SQL-level `PREPARE`/`DEALLOCATE` привязывает стейтмент к серверному
 * коннекту, которого у клиента после коммита уже нет.
 *
 * Запрет тотален СОЗНАТЕЛЬНО: protocol-level prepared statements PgBouncer ≥1.21
 * проносит (`max_prepared_statements`), но это свойство инфры, а не кода модуля.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class NoSqlPrepareRule extends AbstractDatabaseCallRule
{
    protected function isViolation(string $sql): bool
    {
        return array_any($this->statements($sql), fn (string $statement): bool => preg_match('/^(prepare|deallocate)\b/i', $statement) === 1);
    }

    protected function message(): string
    {
        return 'SQL-level PREPARE/DEALLOCATE is forbidden under transaction pooling (B-10 §7.3 п.5).';
    }

    protected function identifier(): string
    {
        return 'modules.sqlPrepare';
    }
}
