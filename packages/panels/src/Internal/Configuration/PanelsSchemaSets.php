<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Configuration;

use Packman\Attributes\Internal;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;

/**
 * Registers the tenant-scoped `sys_saved_view*` schema set owned by `axiomasoft/packman-panels`.
 */
#[Internal]
final readonly class PanelsSchemaSets implements SchemaSetProvider
{
    /** @return list<SchemaSet> */
    public function sets(): array
    {
        $root = dirname(__DIR__, 3).'/database/migrations';

        return [
            new SchemaSet(
                package: 'axiomasoft/packman-panels',
                name: 'tenant',
                scope: SchemaScope::Tenant,
                path: $root.'/tenant',
                version: 1,
                drivers: ['pgsql', 'sqlite'],
                lifecycle: SchemaLifecycle::Platform,
            ),
        ];
    }
}
