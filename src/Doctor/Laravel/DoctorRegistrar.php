<?php

declare(strict_types=1);

namespace Packman\Doctor\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Doctor\Checks\ConfigValidCheck;
use Packman\Doctor\Checks\SchemaStatusCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Internal\DoctorRegistry;
use Packman\Doctor\Internal\DoctorReportBuilder;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DoctorRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(DoctorRegistry::class, static fn (Application $app): DoctorRegistry => new DoctorRegistry($app));
        $app->alias(DoctorRegistry::class, DoctorRegistryContract::class);
        $app->singleton(DoctorReportBuilder::class);

        $app->singleton(ConfigValidCheck::class);
        $app->singleton(SchemaStatusCheck::class);
        $app->tag([ConfigValidCheck::class, SchemaStatusCheck::class], DoctorChecks::TAG);
    }
}
