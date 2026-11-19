<?php

declare(strict_types=1);

namespace Packman\Modules\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Internal\Settings\CompiledSettingDefaultsProvider;
use Packman\Settings\Contracts\SettingDefaultsProviderInterface;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class SettingsRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(
            SettingDefaultsProviderInterface::class,
            static fn (Application $app): CompiledSettingDefaultsProvider => new CompiledSettingDefaultsProvider(
                $app->make(ModuleRegistry::class),
            ),
        );
    }
}
