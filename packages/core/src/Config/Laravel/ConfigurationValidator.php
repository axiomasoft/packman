<?php

declare(strict_types=1);

namespace Packman\Config\Laravel;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Exceptions\InvalidConfiguration;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ConfigurationValidator
{
    /** @var list<string> */
    private array $items = [];

    public function add(string $path, string $message): void
    {
        $this->items[] = $path.': '.$message;
    }

    public function failIfAny(): void
    {
        if ($this->items === []) {
            return;
        }

        throw new InvalidConfiguration($this->items);
    }
}
