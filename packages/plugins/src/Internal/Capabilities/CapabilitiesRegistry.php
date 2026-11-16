<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Capabilities;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Exceptions\Graph\MissingExtensionException;
use Packman\Plugins\Internal\Contributions\FrozenContributions;

/**
 * Resolves capabilities of the compiled plugin graph from config overrides or static providers.
 */
final readonly class CapabilitiesRegistry implements Capabilities
{
    public function __construct(
        private FrozenContributions $graph,
        private Container $container,
    ) {}

    public function has(string $contract): bool
    {
        return $this->available($contract) !== [];
    }

    public function require(string $contract): object
    {
        if (($this->graph->capabilities()[$contract] ?? null)?->cardinality === Cardinality::Many) {
            throw MissingExtensionException::notSingle($contract);
        }

        $classes = $this->available($contract);

        if ($classes === []) {
            throw MissingExtensionException::capability($contract);
        }

        $instance = $this->container->bound($contract)
            ? $this->container->make($contract)
            : $this->container->make($classes[0]);

        if (! $instance instanceof $contract) {
            throw MissingExtensionException::capability($contract);
        }

        return $instance;
    }

    public function all(string $contract): array
    {
        return array_map(fn (string $class): object => $this->make($contract, $class), $this->available($contract));
    }

    /** @return list<class-string> */
    private function available(string $contract): array
    {
        $capabilities = $this->graph->capabilities();

        if (! isset($capabilities[$contract])) {
            return [];
        }

        $classes = [];

        foreach ($this->graph->providers()[$contract] ?? [] as $provider) {
            $class = $provider->configKey === null
                ? $provider->implementation
                : $this->configured($provider->configKey) ?? $provider->default;

            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @return class-string|null */
    private function configured(string $key): ?string
    {
        if (! $this->container->bound(Repository::class)) {
            return null;
        }

        $config = $this->container->make(Repository::class);
        $value = $config->get($key);

        if (is_string($value) && $value !== '' && class_exists($value)) {
            return $value;
        }

        return null;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @param  class-string  $class
     * @return T
     */
    private function make(string $contract, string $class): object
    {
        $instance = $this->container->make($class);

        if (! $instance instanceof $contract) {
            throw MissingExtensionException::capability($contract);
        }

        return $instance;
    }
}
