<?php

declare(strict_types=1);

namespace Packman\Testkit\Contracts\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Resolves contract schema and OpenAPI artifact locations for test assertions. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ContractFiles
{
    public function openApiPath(): string;

    public function schemaPath(string $relativePath): string;
}
