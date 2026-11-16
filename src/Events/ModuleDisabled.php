<?php

declare(strict_types=1);

namespace Packman\Modules\Events;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Events\DomainEvent;
use Packman\Modules\Enums\DisableReason;

/**
 * Domain event dispatched when a module is disabled. Name matches the
 * domain-events table (`mod.module.disabled`).
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ModuleDisabled extends DomainEvent
{
    public function __construct(
        public string $module,
        public DisableReason $reason,
    ) {
        parent::__construct();
    }

    #[Override]
    public static function name(): string
    {
        return 'mod.module.disabled';
    }
}
