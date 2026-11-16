<?php

declare(strict_types=1);

namespace Packman\Schema\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Data\SchemaMigrationStatus;
use Packman\Schema\Enums\SchemaScope;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface SchemaMigrator
{
    /** @return list<string> migrations run, in order */
    public function migrate(SchemaScope $scope, ?string $connection = null, bool $includeModules = false): array;

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function migrateSets(array $ids, ?string $connection = null): array;

    /** @return list<SchemaMigrationStatus> */
    public function status(SchemaScope $scope, ?string $connection = null): array;
}
