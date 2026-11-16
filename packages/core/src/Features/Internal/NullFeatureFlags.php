<?php

declare(strict_types=1);

namespace Packman\Features\Internal;

use Packman\Attributes\Internal;
use Packman\Features\Contracts\FeatureFlagsInterface;
use Packman\Features\Data\FeatureFlagDefinition;

/** Default no-op feature-flag store when no persistent implementation is bound. */
#[Internal]
final readonly class NullFeatureFlags implements FeatureFlagsInterface
{
    public function define(FeatureFlagDefinition $def): void {}
}
