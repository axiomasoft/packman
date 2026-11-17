<?php

declare(strict_types=1);

namespace Packman\Plugins\Testing;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Internal\Graph\PluginEntry;
use Packman\Plugins\Internal\Graph\PluginGraphCompiler;

/**
 * Compiles a plugin set deterministically without booting providers. For plugin contract tests and tooling.
 */
#[Api(since: '1.0')]
final class PluginGraph
{
    /**
     * @param  list<class-string>  $plugins
     * @param  list<class-string<ExtensionPlugin>>  $builtIn
     * @param  list<string>  $disabled
     *
     * @throws InvalidConfiguration when the graph is invalid
     */
    public static function compile(
        Container $container,
        array $plugins,
        string $corePluginId = '',
        array $builtIn = [],
        ?string $database = null,
        array $disabled = [],
        ?PluginActivationGate $activationGate = null,
    ): Contributions&PluginInspector {
        $entries = [];

        foreach ($builtIn as $class) {
            $entries[] = PluginEntry::builtIn($class);
        }

        foreach ($plugins as $index => $class) {
            $entries[] = PluginEntry::config($class, $index);
        }

        return new PluginGraphCompiler($container, $corePluginId, $database, $activationGate)->compile($entries, $disabled);
    }
}
