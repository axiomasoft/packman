<?php

declare(strict_types=1);

namespace Packman\DevTools\Contracts\Gates;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;

/**
 * Automated architecture, boundary, contract, or quality check executed by GateRunner.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface QualityGate
{
    public function id(): string;

    public function category(): string;

    public function description(): string;

    public function run(GateContext $context): GateResult;
}
