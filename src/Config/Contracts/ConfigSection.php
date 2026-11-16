<?php

declare(strict_types=1);

namespace Packman\Config\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * One typed slice of a package's configuration. `compile()` turns the raw array under `key()` into one
 * immutable settings object; the runtime never reads that array again.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ConfigSection
{
    public function key(): string;

    /** @return class-string */
    public function settingsClass(): string;

    public function compile(ConfigReader $config): object;
}
