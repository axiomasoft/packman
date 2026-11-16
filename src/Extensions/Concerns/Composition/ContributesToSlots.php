<?php

declare(strict_types=1);

namespace Packman\Extensions\Concerns\Composition;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Contracts\Composition\WithSlots;

/**
 * Default implementation for {@see WithSlots}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait ContributesToSlots
{
    /** @return array<class-string, array{kind: string, cardinality?: string}> */
    public function definedSlots(): array
    {
        return [];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    public function slotContributions(): array
    {
        return [];
    }
}
