<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs\Lifecycle;

use Packman\Features\Data\FeatureFlagDefinition;
use Packman\Modules\Contracts\ModuleDefinition;
use Packman\Modules\Data\ModuleManifest;

/**
 * No migrations/hooks — exists solely to exercise `ModuleManifest::$featureFlags`
 * → `FeatureFlags::define()` on `enable()` (P1.25, A68).
 */
final class StubFeatureFlagModule extends ModuleDefinition
{
    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('packman/stub-feature-flag')
            ->title('Stub Feature Flag')
            ->tablePrefix('sff')
            ->featureFlags([
                new FeatureFlagDefinition(key: 'sff.beta_mode', default: false, module: 'packman/stub-feature-flag'),
            ]);
    }
}
