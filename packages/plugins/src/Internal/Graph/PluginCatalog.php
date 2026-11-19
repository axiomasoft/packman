<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Graph;

use Illuminate\Contracts\Container\Container;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;
use Packman\Plugins\Exceptions\Registry\RegistryFrozenException;
use Packman\Plugins\Internal\Contributions\FrozenContributions;

/** Collects built-in plugin classes until compilation, then freezes. */
final class PluginCatalog
{
    /** @var array<class-string<ExtensionPlugin>, true> */
    private array $builtIn = [];

    /** @var array<class-string<PluginActivationGate>, true> */
    private array $gates = [];

    private bool $sealed = false;

    private bool $compiling = false;

    public function __construct(private readonly string $configPath = 'packman.plugins') {}

    /** @param class-string<ExtensionPlugin> $class */
    public function builtIn(string $class): void
    {
        if (isset($this->builtIn[$class])) {
            return;
        }

        $this->guard();
        $this->builtIn[$class] = true;
    }

    /** @param class-string<PluginActivationGate> $gate */
    public function addActivationGate(string $gate): void
    {
        if (isset($this->gates[$gate])) {
            return;
        }

        $this->guard();
        $this->gates[$gate] = true;
    }

    /** @param class-string<PluginActivationGate>|null $except */
    public function activationGate(Container $container, ?string $except = null): ?PluginActivationGate
    {
        $gates = [];

        if ($container->bound(PluginActivationGate::class)) {
            $gates[] = $this->resolveGate($container, PluginActivationGate::class);
        }
        foreach (array_keys($this->gates) as $class) {
            if ($class !== $except) {
                $gates[] = $this->resolveGate($container, $class);
            }
        }

        return match (count($gates)) {
            0 => null,
            1 => $gates[0],
            default => new CombinedActivationGate($gates),
        };
    }

    /** @param class-string<PluginActivationGate> $class */
    private function resolveGate(Container $container, string $class): PluginActivationGate
    {
        $gate = $container->make($class);

        if (! $gate instanceof PluginActivationGate) {
            throw new InvalidConfiguration(["packman.plugins: activation gate '{$class}' must implement ".PluginActivationGate::class]);
        }

        return $gate;
    }

    private function guard(): void
    {
        if ($this->sealed || $this->compiling) {
            throw RegistryFrozenException::forRegistry('plugin');
        }
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

    /**
     * @param  list<mixed>  $configured
     * @param  list<string>  $disabled
     */
    public function compile(Container $container, array $configured, array $disabled): FrozenContributions
    {
        if ($this->compiling) {
            throw new InvalidConfiguration(['packman.plugins: recursive plugin graph compilation']);
        }

        $this->compiling = true;

        try {
            $compiler = new PluginGraphCompiler($container, activationGate: $this->activationGate($container));

            $registry = $compiler->compile($this->entries($configured), $disabled);
            $this->sealed = true;

            return $registry;
        } finally {
            $this->compiling = false;
        }
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
