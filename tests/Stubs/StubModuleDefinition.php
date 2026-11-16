<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs;

use Packman\Modules\Contracts\ModuleDefinition;
use Packman\Modules\Data\ModuleManifest;
use Packman\Modules\Internal\Settings\DefaultSettingDefinition;

final class StubModuleDefinition extends ModuleDefinition
{
    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('packman/stub-module')
            ->title('Stub Module')
            ->tablePrefix('stb')
            ->settings([
                DefaultSettingDefinition::make('greeting', 'hello'),
            ]);
    }
}
