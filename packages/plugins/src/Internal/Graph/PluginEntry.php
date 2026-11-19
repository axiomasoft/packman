<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Graph;

use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Enums\Plugin\PluginSource;

/** A plugin class waiting to be compiled, with its origin for error messages. */
#[Internal]
final readonly class PluginEntry
{
    /**
     * @param  class-string  $class
     * @param  string  $origin  e.g. `packman.plugins.0` for config, `builtin <class>` for a package
     */
    private function __construct(public string $class, public PluginSource $source, public string $origin) {}

    /** @param class-string<ExtensionPlugin> $class */
    public static function builtIn(string $class): self
    {
        return new self($class, PluginSource::BuiltIn, 'builtin '.$class);
    }

    /** @param class-string $class */
    public static function config(string $class, int|string $index, string $configPath = 'packman.plugins'): self
    {
        return new self($class, PluginSource::Config, "{$configPath}.{$index}");
    }
}
