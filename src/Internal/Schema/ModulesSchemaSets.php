<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Schema;

use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;

/**
 * Registers the tenant-scoped `mod_*` control-plane schema set owned by `axiomasoft/packman-modules`.
 *
 * @internal
 */
final readonly class ModulesSchemaSets implements SchemaSetProvider
{
    /** @return list<SchemaSet> */
    public function sets(): array
    {
        return [
            new SchemaSet(
                package: 'axiomasoft/packman-modules',
                name: 'tenant',
                scope: SchemaScope::Tenant,
                path: dirname(__DIR__, 3).'/database/migrations/tenant',
                version: 1,
                drivers: ['pgsql'],
                lifecycle: SchemaLifecycle::Platform,
            ),
        ];
    }
}
