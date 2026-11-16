<?php

declare(strict_types=1);

namespace Packman\Extensions\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithCapabilities
{
    /** @return array<class-string, string> contract => cardinality ('required-single'|'optional-single'|'many') */
    public function definedCapabilities(): array;

    /** @return array<class-string, class-string|array{config: string, default?: class-string|null}> */
    public function providedCapabilities(): array;
}
