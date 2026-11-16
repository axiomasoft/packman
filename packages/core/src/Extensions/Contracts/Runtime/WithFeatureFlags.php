<?php

declare(strict_types=1);

namespace Packman\Extensions\Contracts\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Features\Data\FeatureFlagDefinition;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithFeatureFlags
{
    /** @return list<FeatureFlagDefinition> */
    public function featureFlags(): array;
}
