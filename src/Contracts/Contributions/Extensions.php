<?php

declare(strict_types=1);

namespace Packman\Plugins\Contracts\Contributions;

use Packman\Attributes\Api;
use Packman\Plugins\Data\Contributions\SlotContribution;
use Packman\Plugins\Exceptions\Graph\MissingExtensionException;

/**
 * Active slot contributions from the sealed extension graph, ordered by priority ascending, then id.
 */
#[Api(since: '1.0')]
interface Extensions
{
    /**
     * @param  class-string  $slot
     * @return list<SlotContribution>
     */
    public function contributions(string $slot): array;

    /**
     * @template T of object
     *
     * @param  class-string<T>  $slot
     * @return list<T>
     */
    public function resolve(string $slot): array;

    /**
     * @param  class-string  $slot
     * @return list<string>
     */
    public function handlerKeys(string $slot): array;

    /**
     * @template T of object
     *
     * @param  class-string<T>  $slot
     * @return T
     *
     * @throws MissingExtensionException for a key no active plugin contributes
     */
    public function handler(string $slot, string $key): object;

    /**
     * @param  class-string  $slot
     * @return class-string
     *
     * @throws MissingExtensionException for a key no active plugin contributes
     */
    public function handlerClass(string $slot, string $key): string;
}
