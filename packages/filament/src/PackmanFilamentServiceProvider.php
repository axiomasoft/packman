<?php

declare(strict_types=1);

namespace Packman\Filament;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Filament\Laravel\FilamentBindingsRegistrar;
use Packman\Filament\Laravel\FilamentSecurityRegistrar;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class PackmanFilamentServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        FilamentBindingsRegistrar::class,
        FilamentSecurityRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/packman-filament.php', 'packman-filament');
        $this->registerPackageRegistrars();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/packman-filament.php' => $this->app->configPath('packman-filament.php'),
            ], 'packman-filament-config');
        }

        $this->bootPackageRegistrars();
    }
}
