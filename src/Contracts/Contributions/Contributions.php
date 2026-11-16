<?php

declare(strict_types=1);

namespace Packman\Plugins\Contracts\Contributions;

use Packman\Attributes\Api;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

/**
 * Compiled, immutable plugin registry. Safe across long-running Octane workers.
 */
#[Api(since: '1.0')]
interface Contributions
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $interface
     * @return T
     *
     * @throws UnsupportedContribution unknown kind, key not contributed, or wrong contract
     */
    public function get(string $kind, string $key, string $interface): object;

    public function has(string $kind, string $key): bool;

    /**
     * Keys in plugin load order (dependencies first), then registration order. Empty for an unused kind.
     *
     * @return list<string>
     */
    public function keys(string $kind): array;
}
