<?php

declare(strict_types=1);

namespace Packman\Modules;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\ConfigReportSectionInterface;
use Packman\Errors\Laravel\ProblemDefinitions;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Modules\Internal\Errors\ModulesProblemDefinitions;
use Packman\Modules\Internal\Support\ModulesConfigSection;
use Packman\Modules\Internal\Support\ModulesOptions;
use Packman\Modules\Laravel\LifecycleRegistrar;
use Packman\Modules\Laravel\ModulesBootRegistrar;
use Packman\Modules\Laravel\ModulesRegistrar;
use Packman\Modules\Laravel\RegistryRegistrar;
use Packman\Modules\Laravel\SettingsRegistrar;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ModulesServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        RegistryRegistrar::class,
        LifecycleRegistrar::class,
        SettingsRegistrar::class,
        ModulesBootRegistrar::class,
        ModulesRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modules.php', 'modules');
        $this->app->tag([ModulesConfigSection::class], ConfigReportSectionInterface::class);

        ProblemDefinitions::register(
            app: $this->app,
            composerManifest: __DIR__.'/../composer.json',
            provider: ModulesProblemDefinitions::class,
        );

        $this->registerPackageRegistrars();
    }

    public function boot(): void
    {
        $options = $this->app->make(ModulesOptions::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/modules.php' => $this->app->configPath('modules.php'),
            ], 'packman-modules-config');
        }

        // Deploy hook: `php artisan optimize` recompiles the registry.
        $this->optimizes(optimize: $options->compileCommand, key: $options->configRoot);

        $this->bootPackageRegistrars();
    }
}
