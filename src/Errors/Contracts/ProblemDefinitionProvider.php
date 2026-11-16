<?php

declare(strict_types=1);

namespace Packman\Errors\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Data\ProblemDefinition;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ProblemDefinitionProvider
{
    /** @return iterable<ProblemDefinition> */
    public function definitions(): iterable;
}
