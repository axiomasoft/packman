<?php

declare(strict_types=1);

namespace Packman\Panels\Laravel;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Panels\Internal\Configuration\PanelsSchemaSets;
use Packman\Panels\SavedViews\Laravel\SavedViewsBindingsRegistrar;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class PanelsServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        SavedViewsBindingsRegistrar::class,
        PanelsBindingsRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        if (interface_exists(SchemaSetProvider::class)) {
            $this->app->tag([PanelsSchemaSets::class], SchemaSetProvider::class);
        }

        $this->mergeConfigFrom(__DIR__.'/../../config/packman-panels.php', 'packman-panels');
        $this->registerPackageRegistrars();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/packman-panels.php' => $this->app->configPath('packman-panels.php'),
            ], 'packman-panels-config');
        }

        $this->bootPackageRegistrars();
    }
}
