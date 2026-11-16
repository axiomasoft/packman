<?php

declare(strict_types=1);

namespace Packman\Plugins\Laravel;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;

#[Api(since: '1.0')]
final class PluginsServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string> */
    protected const array REGISTRARS = [
        ExtensionRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/packman-plugins.php', 'packman.plugins');
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->bootRegistrars();
    }
}
