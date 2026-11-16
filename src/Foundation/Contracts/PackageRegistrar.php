<?php

declare(strict_types=1);

namespace Packman\Foundation\Contracts;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PackageRegistrar
{
    public function register(Application $app): void;

    public function boot(Application $app): void;
}
