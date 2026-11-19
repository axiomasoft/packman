<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Support;

use Packman\Attributes\Internal;
use Packman\Config\Contracts\ConfigReportSectionInterface;

/** @internal Configuration report composition. */
#[Internal]
final class ModulesConfigSection implements ConfigReportSectionInterface
{
    public function order(): int
    {
        return 25;
    }

    public function options(): array
    {
        return [ModulesOptions::class => ['modules']];
    }

    public function secretKeys(): array
    {
        return [];
    }

    public function secretDefaults(): array
    {
        return [];
    }

    public function drivers(): array
    {
        return [];
    }

    public function adapters(): array
    {
        return [];
    }
}
