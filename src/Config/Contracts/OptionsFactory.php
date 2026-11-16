<?php

declare(strict_types=1);

namespace Packman\Config\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface OptionsFactory
{
    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config, string $path): self;
}
