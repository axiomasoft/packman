<?php

declare(strict_types=1);

namespace Packman\Extensions\Concerns\Persistence;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Contracts\Persistence\WithDatabaseRegistration;

/**
 * Default opt-in implementation for {@see WithDatabaseRegistration} (stateful Bitrix/WordPress-style DB registration).
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait RegistersInDatabase
{
    public function persistsStateInDatabase(): bool
    {
        return true;
    }

    public function tracksOwnedRecords(): bool
    {
        return true;
    }

    public function defaultEdition(): string
    {
        return 'standard';
    }
}
