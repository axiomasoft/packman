<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Graph;

use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Exceptions\Registry\RegistryFrozenException;

/** Collects built-in plugin classes until compilation, then freezes. */
final class PluginCatalog
{
    /** @var array<class-string<ExtensionPlugin>, true> */
    private array $builtIn = [];

    private bool $sealed = false;

    public function __construct(private readonly string $configPath = 'packman.plugins') {}

    /** @param class-string<ExtensionPlugin> $class */
    public function builtIn(string $class): void
    {
        if ($this->sealed) {
            throw RegistryFrozenException::forRegistry('plugin');
        }

        $this->builtIn[$class] = true;
    }

    public function hasBuiltIn(): bool
    {
        return $this->builtIn !== [];
    }

    /** @return list<class-string<ExtensionPlugin>> */
    public function builtInClasses(): array
    {
        return array_keys($this->builtIn);
    }

    public function seal(): self
    {
        $this->sealed = true;

        return $this;
    }

    /**
     * @param  list<mixed>  $configuredPlugins
     * @return list<PluginEntry>
     */
    public function entries(array $configuredPlugins = []): array
    {
        $entries = [];

        foreach (array_keys($this->builtIn) as $class) {
            $entries[] = PluginEntry::builtIn($class);
        }

        foreach ($configuredPlugins as $index => $class) {
            if (! is_string($class) || $class === '') {
                throw new InvalidConfiguration(["{$this->configPath}.{$index}: expected a plugin class name"]);
            }

            /** @var class-string $class */
            $entries[] = PluginEntry::config($class, $index, $this->configPath);
        }

        return $entries;
    }
}
