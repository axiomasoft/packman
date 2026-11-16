<?php

declare(strict_types=1);

namespace Packman\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Packman\PackmanServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
        ];
    }
}
