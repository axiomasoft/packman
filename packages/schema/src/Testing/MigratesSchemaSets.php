<?php

declare(strict_types=1);

namespace Packman\Schema\Testing;

use LogicException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Contracts\SchemaMigrator;
use Packman\Schema\Enums\SchemaScope;

/**
 * Testbench helper: builds package tables through the same registry production uses.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
trait MigratesSchemaSets
{
    /** @param list<string> $ids */
    protected function migrateSchemaSets(array $ids, ?string $connection = null): void
    {
        ($this->app ?? throw new LogicException('Boot the test application before migrating schema sets.'))->make(SchemaMigrator::class)->migrateSets($ids, $connection);
    }

    protected function migrateSchemaScope(SchemaScope $scope = SchemaScope::Tenant, ?string $connection = null, bool $includeModules = true): void
    {
        ($this->app ?? throw new LogicException('Boot the test application before migrating schema sets.'))->make(SchemaMigrator::class)->migrate($scope, $connection, $includeModules);
    }
}
