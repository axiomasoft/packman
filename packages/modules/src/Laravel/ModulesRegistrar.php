<?php

declare(strict_types=1);

namespace Packman\Modules\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\ApplicationLocator;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Modules\Contracts\ModuleRegistrar;
use Packman\Modules\Internal\ServiceProviderModuleRegistrar;

/** Composition of the kernel module; the public provider delegates here. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ModulesRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(ModuleRegistrar::class, static fn (): ServiceProviderModuleRegistrar => new ServiceProviderModuleRegistrar(ApplicationLocator::current(...)));
    }

    #[Override]
    public function boot(Application $app): void {}
}
