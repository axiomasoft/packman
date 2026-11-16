<?php

declare(strict_types=1);

namespace Packman\Schema\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\SpaceSchema;

/**
 * One immutable schema change of one component, applied to one space.
 *
 * A step reads nothing but the SpaceSchema it is given: no config(), no container, no feature flags, no shared helper
 * whose output may change later. Its output is therefore a pure function of the space's storage decisions.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SchemaStep
{
    public function up(SpaceSchema $schema): void;

    public function down(SpaceSchema $schema): void;
}
