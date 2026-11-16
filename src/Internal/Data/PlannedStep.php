<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Data;

use Packman\Schema\Contracts\SchemaStep;

/** One (space x component x step) = one migration. */
final readonly class PlannedStep
{
    /** @param  class-string<SchemaStep>  $class */
    public function __construct(
        public string $space,
        public string $component,
        public string $step,
        public string $class,
        public string $migration,
    ) {}
}
