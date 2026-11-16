<?php

declare(strict_types=1);

namespace Packman\Extensions\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithDatabaseSettings
{
    public function settingsNamespace(): string;

    /** @return array<string, mixed> */
    public function defaultSettings(): array;

    /** @return list<string> */
    public function sensitiveSettings(): array;
}
