<?php

declare(strict_types=1);

namespace Packman\Modules\PHPStan\Rules\Pooling;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * `SET` держит состояние в сессии, которую transaction pooling отдаёт
 * другому клиенту после коммита. Легален только `SET LOCAL` в транзакции.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class NoSetWithoutLocalRule extends AbstractDatabaseCallRule
{
    protected function isViolation(string $sql): bool
    {
        return array_any($this->statements($sql), fn (string $statement): bool => preg_match('/^set\s+(?!local\b)/i', $statement) === 1);
    }

    protected function message(): string
    {
        return 'Session-level SET is forbidden under transaction pooling (B-10 §7.3 п.1) — use SET LOCAL inside a transaction.';
    }

    protected function identifier(): string
    {
        return 'modules.setWithoutLocal';
    }
}
