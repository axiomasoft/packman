<?php

declare(strict_types=1);

namespace Fixture\StandaloneLaravel;

final class DefaultInvoiceFormatter implements InvoiceFormatter
{
    public function format(int $minorUnits, string $currency): string
    {
        return sprintf('%s %.2f', $currency, $minorUnits / 100);
    }
}
