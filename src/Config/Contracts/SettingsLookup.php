<?php

declare(strict_types=1);

namespace Packman\Config\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface SettingsLookup
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function get(string $class): object;
}
