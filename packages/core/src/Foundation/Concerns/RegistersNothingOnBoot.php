<?php

declare(strict_types=1);

namespace Packman\Foundation\Concerns;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
trait RegistersNothingOnBoot
{
    #[Override]
    public function boot(Application $app): void {}
}
