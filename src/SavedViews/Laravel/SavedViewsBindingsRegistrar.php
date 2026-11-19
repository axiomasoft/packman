<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Modules\Contracts\Entities\EntityRegistry;
use Packman\Panels\Internal\Subjects\EntityViewSchemaProvider;
use Packman\Panels\SavedViews\Contracts\SavedViewRepository;
use Packman\Panels\SavedViews\Contracts\ViewAccessPolicy;
use Packman\Panels\SavedViews\Contracts\ViewSchemaProvider;
use Packman\Panels\SavedViews\Internal\DenyViewAccessPolicy;
use Packman\Panels\SavedViews\Internal\UnavailableViewSchemaProvider;
use Packman\Panels\SavedViews\Internal\ViewConfigValidator;

/** Saved-view bindings of the panels provider. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class SavedViewsBindingsRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(ViewSchemaProvider::class, static function (Application $app): ViewSchemaProvider {
            if (interface_exists(EntityRegistry::class) && $app->bound(EntityRegistry::class)) {
                return new EntityViewSchemaProvider(entities: $app->make(EntityRegistry::class));
            }

            return new UnavailableViewSchemaProvider;
        });

        $app->singletonIf(ViewAccessPolicy::class, DenyViewAccessPolicy::class);
        $app->singleton(ViewConfigValidator::class);
        $app->singleton(SavedViewRepository::class, static fn (Application $app): SavedViewRepository => (new SavedViewRepositoryFactory)->make($app));
    }
}
