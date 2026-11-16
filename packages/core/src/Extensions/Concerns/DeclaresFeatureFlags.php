<?php

declare(strict_types=1);

namespace Packman\Extensions\Concerns;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Contracts\WithFeatureFlags;
use Packman\Features\Data\FeatureFlagDefinition;

/**
 * Default implementation for {@see WithFeatureFlags}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait DeclaresFeatureFlags
{
    /** @return list<FeatureFlagDefinition> */
    public function featureFlags(): array
    {
        return [];
    }
}
