<?php

declare(strict_types=1);

use Packman\Filament\Tests\Support\FilamentTestCase;
use Packman\Tests\TestCase;

uses(TestCase::class)->in(
    '../packages/core/tests/Feature',
    '../packages/modules/tests/Feature',
    '../packages/modules/tests/Unit/ExtensionRemediationTest.php',
    '../packages/modules/tests/Unit/RegistryCompilerDeterminismTest.php',
    '../packages/panels/tests/Feature',
    '../packages/plugins/tests/Feature',
    '../packages/schema/tests/Feature',
);

uses(FilamentTestCase::class)->in(
    '../packages/filament/tests/Feature',
);
