<?php

declare(strict_types=1);

namespace Packman\Extensions\Concerns;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Contracts\WithSchema;

/**
 * Default implementation for {@see WithSchema}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait OwnsSchema
{
    public function tablePrefix(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function tableNouns(): array
    {
        return [];
    }

    public function migrationPath(): ?string
    {
        return null;
    }

    /** @return list<class-string> */
    public function schemaSetProviders(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function schemaComponents(): array
    {
        return [];
    }
}
