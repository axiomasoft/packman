<?php

declare(strict_types=1);

namespace Packman\Testkit\Concerns\Conformance;

use Packman\Attributes\Api;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\ExtensionInspector;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginDescription;
use Packman\Plugins\Testing\PluginGraph;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Reusable conformance assertions for ExtensionPlugin implementations.
 */
#[Api(since: '1.0')]
trait PluginContractTests
{
    abstract protected function pluginUnderTest(): ExtensionPlugin;

    /** @return list<class-string<ExtensionPlugin>> */
    protected function dependencyPluginClasses(): array
    {
        return [];
    }

    public function test_plugin_declares_valid_manifest_and_compiles_deterministically(): void
    {
        if (! class_exists(PluginGraph::class)) {
            throw new RuntimeException('axiomasoft/packman-plugins is required to execute PluginContractTests.');
        }

        $plugin = $this->pluginUnderTest();
        $manifest = $plugin->manifest();

        Assert::assertMatchesRegularExpression('/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+\z/', $manifest->id);
        Assert::assertNotSame('', $manifest->version);

        $pluginClasses = array_values(array_unique([...$this->dependencyPluginClasses(), $plugin::class]));
        $registry = PluginGraph::compile(app(), $pluginClasses);
        $compiledIds = array_map(static fn (PluginDescription $description): string => $description->id, $registry->describe());

        Assert::assertContains($manifest->id, $compiledIds, "Compiled plugin graph does not contain [{$manifest->id}].");

        $profile = ExtensionInspector::inspect($plugin);

        if ($plugin instanceof WithConfiguration) {
            Assert::assertTrue($profile->hasConfiguration);
        }
    }
}
