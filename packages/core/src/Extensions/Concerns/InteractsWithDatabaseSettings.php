<?php

declare(strict_types=1);

namespace Packman\Extensions\Concerns;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Contracts\WithDatabaseSettings;

/**
 * Default implementation for {@see WithDatabaseSettings}; override `settingsNamespace()` and `defaultSettings()` / `sensitiveSettings()`.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait InteractsWithDatabaseSettings
{
    /** @return array<string, mixed> */
    public function defaultSettings(): array
    {
        return [];
    }

    /** @return list<string> */
    public function sensitiveSettings(): array
    {
        return [];
    }
}
