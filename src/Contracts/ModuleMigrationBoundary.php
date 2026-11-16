<?php

declare(strict_types=1);

namespace Packman\Schema\Contracts;

use Closure;
use Illuminate\Database\Connection;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Optional boundary implemented by module runtime packages to run module migrations under a graph lock and ownership claims.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ModuleMigrationBoundary
{
    /**
     * @param  array<string, string>  $owners  canonical migration path => owning module package
     * @param  Closure(): void  $run
     */
    public function run(Connection $connection, string $connectionName, array $owners, Closure $run): void;
}
