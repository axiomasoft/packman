<?php

declare(strict_types=1);

namespace Packman\Config\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** A package's contribution to the effective configuration report. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ConfigReportSectionInterface
{
    public function order(): int;

    /** @return array<class-string, list<string>> */
    public function options(): array;

    /** @return list<string> */
    public function secretKeys(): array;

    /** @return array<string, string> */
    public function secretDefaults(): array;

    /** @return list<string> */
    public function drivers(): array;

    /** @return list<array{line: string, error: ?string}> */
    public function adapters(): array;
}
