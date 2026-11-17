<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Contributions;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Extensions;
use Packman\Plugins\Data\Contributions\SlotContribution;
use Packman\Plugins\Exceptions\Graph\MissingExtensionException;

/**
 * Active slot contributions from the sealed extension graph, ordered by priority ascending, then id.
 */
#[Internal]
final readonly class ExtensionRegistry implements Extensions
{
    public function __construct(
        private FrozenContributions $graph,
        private Container $container,
    ) {}

    /**
     * @param  class-string  $slot
     * @return list<SlotContribution>
     */
    public function contributions(string $slot): array
    {
        return $this->graph->slotContributions()[$slot] ?? [];
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $slot
     * @return list<T>
     */
    public function resolve(string $slot): array
    {
        return array_map(fn (SlotContribution $c): object => $this->make($slot, $c), $this->contributions($slot));
    }

    /**
     * @param  class-string  $slot
     * @return list<string>
     */
    public function handlerKeys(string $slot): array
    {
        return array_values(array_filter(
            array_map(static fn (SlotContribution $c): ?string => $c->key, $this->contributions($slot)),
            static fn (?string $key): bool => $key !== null,
        ));
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $slot
     * @return T
     *
     * @throws MissingExtensionException for a key no active plugin contributes
     */
    public function handler(string $slot, string $key): object
    {
        foreach ($this->contributions($slot) as $contribution) {
            if ($contribution->key === $key) {
                return $this->make($slot, $contribution);
            }
        }

        throw MissingExtensionException::handler($slot, $key);
    }

    /**
     * @param  class-string  $slot
     * @return class-string
     *
     * @throws MissingExtensionException for a key no active plugin contributes
     */
    public function handlerClass(string $slot, string $key): string
    {
        foreach ($this->contributions($slot) as $contribution) {
            if ($contribution->key === $key) {
                return $contribution->implementation;
            }
        }

        throw MissingExtensionException::handler($slot, $key);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $slot
     * @return T
     */
    private function make(string $slot, SlotContribution $contribution): object
    {
        $instance = $this->container->make($contribution->implementation);

        if (! $instance instanceof $slot) {
            throw MissingExtensionException::handler($slot, $contribution->key ?? $contribution->id);
        }

        return $instance;
    }
}
