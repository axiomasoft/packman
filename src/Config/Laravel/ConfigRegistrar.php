<?php

declare(strict_types=1);

namespace Packman\Config\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Internal\ConfigNormalizer;
use Packman\Config\Internal\ConfigSections;
use Packman\Config\Internal\SectionCompiler;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ConfigRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(ConfigSections::class);
        $app->singleton(ConfigNormalizer::class);
        $app->singleton(SectionCompiler::class);
        $app->bind(ConfigurationValidator::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        RuntimeConfigGate::install($app);
    }
}
