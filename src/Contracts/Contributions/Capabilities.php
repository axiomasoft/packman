<?php

declare(strict_types=1);

namespace Packman\Plugins\Contracts\Contributions;

use Packman\Attributes\Api;
use Packman\Plugins\Exceptions\Graph\MissingExtensionException;

/**
 * Resolves active capability providers from the sealed extension graph.
 */
#[Api(since: '1.0')]
interface Capabilities
{
    /** @param class-string $contract */
    public function has(string $contract): bool;

    /**
     * Resolves the single active provider of `$contract`.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     *
     * @throws MissingExtensionException when no active plugin provides it, or it takes many providers
     */
    public function require(string $contract): object;

    /**
     * Resolves every available provider of `$contract`, in plugin load order.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return list<T>
     */
    public function all(string $contract): array;
}
