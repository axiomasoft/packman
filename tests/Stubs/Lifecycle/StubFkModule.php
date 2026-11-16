<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs\Lifecycle;

use Packman\Modules\Contracts\ModuleDefinition;
use Packman\Modules\Data\ModuleManifest;
use Packman\Modules\Internal\Definition\ModuleDependency;

/**
 * Purge/D41 fixture. Owns two tables with a REAL foreign key (stf_children →
 * stf_parents, parent created first) AND, in a later migration, extends the
 * base module's already-existing sba_widgets with a column + index. Exercises:
 *   - FK-safe reverse drop order — child before parent (Blocker A3);
 *   - purge across ALL claim types — a `column` and `index` claim on a table
 *     this module does NOT own must be dropped WITHOUT dropping that table
 *     (D41). Requires stub-base so sba_widgets exists to extend.
 */
final class StubFkModule extends ModuleDefinition
{
    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('packman/stub-fk')
            ->title('Stub FK')
            ->tablePrefix('stf')
            ->requires([ModuleDependency::module('packman/stub-base')]);
    }
}
