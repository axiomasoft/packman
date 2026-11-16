<?php

declare(strict_types=1);

namespace Packman;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Internal\Commands\ValidateConfigCommand;
use Packman\Config\Laravel\ConfigRegistrar;
use Packman\Doctor\Laravel\Console\DoctorCommand;
use Packman\Doctor\Laravel\DoctorRegistrar;
use Packman\Errors\Internal\PackageProblemDefinitions;
use Packman\Errors\Laravel\ErrorsRegistrar;
use Packman\Errors\Laravel\ProblemDefinitions;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class PackmanServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        ErrorsRegistrar::class,
        ConfigRegistrar::class,
        DoctorRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        ProblemDefinitions::register(
            app: $this->app,
            composerManifest: __DIR__.'/../composer.json',
            provider: PackageProblemDefinitions::class,
        );

        $this->mergeConfigFrom(__DIR__.'/../config/packman.php', 'packman');
        $this->registerPackageRegistrars();
    }

    public function boot(): void
    {
        $this->bootPackageRegistrars();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ValidateConfigCommand::class,
                DoctorCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/packman.php' => config_path('packman.php'),
            ], 'packman-config');
        }
    }
}
