<?php

declare(strict_types=1);

namespace Packman\Schema\Contracts\Compiler;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Optional for a SchemaComponent: step ids that only unreleased builds had and that were folded into or renamed
 * within another step before the first release. A migration file that still names one gets an error saying so (and
 * how to recover) instead of "the installed package is older than the migration". A released step is never retired.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface RetiresSteps
{
    /** @return array<string, string> retired step id => what replaced it, e.g. 'renamed to 2026_10_01_000301_create_polls' */
    public function retiredSteps(): array;
}
