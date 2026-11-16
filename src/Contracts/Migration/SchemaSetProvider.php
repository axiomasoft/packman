<?php

declare(strict_types=1);

namespace Packman\Schema\Contracts\Migration;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Data\Migration\SchemaSet;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SchemaSetProvider
{
    /** @return list<SchemaSet> */
    public function sets(): array;
}
