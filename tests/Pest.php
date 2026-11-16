<?php

declare(strict_types=1);

use Packman\Tests\TestCase;

uses(TestCase::class)->in(
    '../packages/core/tests/Feature',
    '../packages/modules/tests/Feature',
    '../packages/modules/tests/Unit/ExtensionRemediationTest.php',
    '../packages/modules/tests/Unit/RegistryCompilerDeterminismTest.php',
    '../packages/plugins/tests/Feature',
    '../packages/schema/tests/Feature',
);
