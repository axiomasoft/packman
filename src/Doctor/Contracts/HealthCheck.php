<?php

declare(strict_types=1);

namespace Packman\Doctor\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;

/**
 * A structured diagnostic slice emitting findings and slice payload data.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface HealthCheck
{
    public function code(): string;

    public function requiresDatabase(): bool;

    /** @return array{0: list<DoctorFinding>, 1: array<string, mixed>} */
    public function slice(DoctorContext $context): array;
}
