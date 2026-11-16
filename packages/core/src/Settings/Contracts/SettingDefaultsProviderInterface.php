<?php

declare(strict_types=1);

namespace Packman\Settings\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Provides setting default values and sensitivity metadata declared by modules or plugins.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SettingDefaultsProviderInterface
{
    public function defaultFor(string $namespace, string $key): mixed;

    public function isSensitive(string $namespace, string $key): bool;
}
