<?php

declare(strict_types=1);

namespace Packman\Plugins\Tests\Unit;

use Packman\Plugins\Concerns\RegistersNothing;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Internal\Graph\PluginDeclarations;
use Packman\Plugins\Laravel\PluginRegistrar;
use PHPUnit\Framework\TestCase;

final class DeclarativePluginTest extends TestCase
{
    public function test_registers_nothing_trait_satisfies_extension_plugin_contract(): void
    {
        $plugin = new class implements ExtensionPlugin
        {
            use RegistersNothing;

            public function manifest(): PluginManifest
            {
                return new PluginManifest('acme.declarative');
            }
        };

        $declarations = new PluginDeclarations;
        $plugin->register(new PluginRegistrar($declarations, $plugin->manifest()->id));
        $declarations->seal();

        self::assertSame([], $declarations->kinds());
        self::assertSame([], $declarations->contributions());
    }
}
