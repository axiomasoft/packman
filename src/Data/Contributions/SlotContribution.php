<?php

declare(strict_types=1);

namespace Packman\Plugins\Data\Contributions;

use Packman\Attributes\Api;

/**
 * One contribution to a slot, ordered by priority ascending, then id.
 */
#[Api(since: '1.0')]
final readonly class SlotContribution
{
    /**
     * @param  class-string  $slot
     * @param  class-string  $implementation
     */
    public function __construct(
        public string $slot,
        public string $id,
        public string $pluginId,
        public string $implementation,
        public int $priority = 0,
        public ?string $key = null,
    ) {}
}
