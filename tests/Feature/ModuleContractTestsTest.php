<?php

declare(strict_types=1);

namespace Packman\Testkit\Tests\Feature;

use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Modules\Contracts\Definition\ModuleDefinition;
use Packman\Modules\Data\Lifecycle\ModuleManifest;
use Packman\Testkit\Concerns\Conformance\ModuleContractTests;
use Packman\Testkit\PackageTestCase;

final class ModuleContractTestsTest extends PackageTestCase
{
    use ModuleContractTests;

    protected function moduleUnderTest(): ModuleDefinition
    {
        return new class extends ModuleDefinition implements WithConfiguration
        {
            use Configurable;

            public function configRoot(): string
            {
                return 'sample-module';
            }

            public function manifest(): ModuleManifest
            {
                return ModuleManifest::make('packman-fixture/sample-module')
                    ->title('Sample Module')
                    ->tablePrefix('sm');
            }
        };
    }
}
