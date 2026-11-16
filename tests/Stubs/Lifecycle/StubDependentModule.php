<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs\Lifecycle;

use Packman\Modules\Contracts\ModuleDefinition;
use Packman\Modules\Data\ModuleManifest;
use Packman\Modules\Internal\Definition\ModuleDependency;

/** Requires {@see StubBaseModule} — exercises AC-6/AC-8/cascade. */
final class StubDependentModule extends ModuleDefinition
{
    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('packman/stub-dependent')
            ->title('Stub Dependent')
            ->tablePrefix('sbd')
            ->requires([ModuleDependency::module('packman/stub-base')]);
    }
}
