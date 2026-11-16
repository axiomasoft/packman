<?php

declare(strict_types=1);

namespace Fixture\StandaloneLaravel;

interface InvoiceFormatter
{
    public function format(int $minorUnits, string $currency): string;
}
