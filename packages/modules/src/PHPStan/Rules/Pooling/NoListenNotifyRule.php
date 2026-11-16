<?php

declare(strict_types=1);

namespace Packman\Modules\PHPStan\Rules\Pooling;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * LISTEN/NOTIFY — сессионный канал; через пулер подписка уезжает на чужой
 * бекенд. Легальный путь доставки — контракт `PubSub`.
 *
 * Запрет тотален СОЗНАТЕЛЬНО: PgBouncer фактически проносит NOTIFY (не LISTEN),
 * но код модуля не должен зависеть от версии и настроек пулера.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class NoListenNotifyRule extends AbstractDatabaseCallRule
{
    protected function isViolation(string $sql): bool
    {
        if (preg_match('/\bpg_notify\s*\(/i', $sql) === 1) {
            return true;
        }

        return array_any($this->statements($sql), fn (string $statement): bool => preg_match('/^(un)?listen\b|^notify\b/i', $statement) === 1);
    }

    protected function message(): string
    {
        return 'LISTEN/NOTIFY is forbidden under transaction pooling (B-10 §7.3 п.4) — publish through the PubSub contract.';
    }

    protected function identifier(): string
    {
        return 'modules.listenNotify';
    }
}
