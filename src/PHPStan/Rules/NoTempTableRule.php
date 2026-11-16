<?php

declare(strict_types=1);

namespace Packman\Modules\PHPStan\Rules;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Временная таблица живёт в сессии, а не в транзакции — после коммита пул
 * отдаёт бекенд другому клиенту, и таблица «исчезает» либо течёт.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class NoTempTableRule extends AbstractDatabaseCallRule
{
    protected function isViolation(string $sql): bool
    {
        return preg_match('/\bcreate\s+(global\s+|local\s+)?(temp|temporary)\s+table\b/i', $sql) === 1;
    }

    protected function message(): string
    {
        return 'Temporary tables are forbidden under transaction pooling (B-10 §7.3 п.2).';
    }

    protected function identifier(): string
    {
        return 'modules.tempTable';
    }
}
