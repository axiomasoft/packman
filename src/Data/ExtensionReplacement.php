<?php

declare(strict_types=1);

namespace Packman\Modules\Data;

use Packman\Attributes\Api;

/**
 * Explicit target→replacement directive for one extension point.
 */
#[Api(since: '1.0')]
final readonly class ExtensionReplacement
{
    public function __construct(
        public string $point,
        public string $targetId,
        public string $replacementId,
    ) {}
}
