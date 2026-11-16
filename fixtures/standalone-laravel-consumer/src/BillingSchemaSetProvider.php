<?php

declare(strict_types=1);

namespace Fixture\StandaloneLaravel;

use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;

final class BillingSchemaSetProvider implements SchemaSetProvider
{
    public function sets(): array
    {
        return [
            new SchemaSet(
                package: 'fixture/billing-plugin',
                name: 'tenant',
                scope: SchemaScope::Tenant,
                path: dirname(__DIR__).'/database/migrations/tenant',
                version: 1,
                drivers: ['pgsql', 'sqlite'],
                lifecycle: SchemaLifecycle::Platform,
            ),
        ];
    }
}
