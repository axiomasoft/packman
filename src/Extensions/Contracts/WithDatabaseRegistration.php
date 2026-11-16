<?php

declare(strict_types=1);

namespace Packman\Extensions\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithDatabaseRegistration
{
    public function persistsStateInDatabase(): bool;

    public function tracksOwnedRecords(): bool;

    public function defaultEdition(): string;
}
