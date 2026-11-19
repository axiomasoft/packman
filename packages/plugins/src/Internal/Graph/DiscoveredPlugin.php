<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Graph;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;

#[Internal]
final readonly class DiscoveredPlugin
{
    public PluginManifest $manifest;

    public function __construct(public PluginEntry $entry, private ExtensionPlugin $prototype)
    {
        $this->manifest = $prototype->manifest();
    }

    public function activeInstance(Container $container): ExtensionPlugin
    {
        $class = $this->prototype::class;

        if (! $container->bound($class)) {
            return $this->prototype;
        }

        $instance = $container->make($class);

        if (! $instance instanceof ExtensionPlugin || $instance::class !== $class || get_object_vars($instance->manifest()) !== get_object_vars($this->manifest)) {
            throw new InvalidConfiguration(["{$this->entry->origin}: the active binding for {$class} must preserve its class and manifest"]);
        }

        return $instance;
    }
}
