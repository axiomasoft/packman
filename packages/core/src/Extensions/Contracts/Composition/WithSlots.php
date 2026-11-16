<?php

declare(strict_types=1);

namespace Packman\Extensions\Contracts\Composition;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithSlots
{
    /** @return array<class-string, array{kind: string, cardinality?: string}> */
    public function definedSlots(): array;

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    public function slotContributions(): array;
}
