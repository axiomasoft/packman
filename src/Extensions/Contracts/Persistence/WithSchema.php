<?php

declare(strict_types=1);

namespace Packman\Extensions\Contracts\Persistence;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithSchema
{
    public function tablePrefix(): ?string;

    /** @return list<string> */
    public function tableNouns(): array;

    public function migrationPath(): ?string;

    /** @return list<class-string> */
    public function schemaSetProviders(): array;

    /** @return list<class-string> */
    public function schemaComponents(): array;
}
