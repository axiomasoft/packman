<?php

declare(strict_types=1);

namespace Packman\Testkit\Tests\Feature;

use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Packman\Testkit\Concerns\Conformance\PluginContractTests;
use Packman\Testkit\PackageTestCase;

final class PluginContractTestsTest extends PackageTestCase
{
    use PluginContractTests;

    protected function pluginUnderTest(): ExtensionPlugin
    {
        return new class implements ExtensionPlugin, WithConfiguration
        {
            use Configurable;

            public function configRoot(): string
            {
                return 'fixture-plugin';
            }

            public function manifest(): PluginManifest
            {
                return new PluginManifest(id: 'packman.fixture-plugin', version: '1.0.0');
            }

            public function register(PluginRegistrar $registrar): void {}
        };
    }
}
