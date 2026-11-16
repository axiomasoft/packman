<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Lifecycle;

use Packman\Attributes\Api;
use Packman\Modules\Contracts\Lifecycle\ModuleLifecycle;
use Packman\Modules\Enums\Lifecycle\ModuleState;

/**
 * Point-in-time snapshot returned by {@see ModuleLifecycle::status()}.
 * `pendingUpgrade` is derived (codeVersion > dbVersion), never stored — a
 * lagging schema is a normal state between deploy and the control-plane's
 * upgrade wave.
 */
#[Api(since: '1.0')]
final readonly class ModuleStatus
{
    public function __construct(
        public string $name,
        public ModuleState $state,
        public string $codeVersion,
        public ?string $dbVersion,
        public bool $pendingUpgrade,
        public string $edition,
    ) {}
}
