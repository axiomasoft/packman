<?php

declare(strict_types=1);

namespace Packman\Doctor\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;

/**
 * One read-only diagnostic of `packman:doctor`. A check never writes, publishes, or migrates.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DoctorCheck
{
    /** Dotted, unique key such as `core.schema.tables`; `--prefix` selects by segment prefix. */
    public function key(): string;

    public function title(): string;

    /** @return list<DoctorFinding> */
    public function run(DoctorContext $context): array;
}
