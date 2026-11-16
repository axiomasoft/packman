<?php

declare(strict_types=1);

namespace Packman\Features\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Features\Data\FeatureFlagDefinition;

/**
 * Contract for idempotent feature flag definition registration across packages and modules.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface FeatureFlagsInterface
{
    /** Idempotent: a second `define()` for the same key never overwrites an admin-toggled flag state. */
    public function define(FeatureFlagDefinition $def): void;
}
