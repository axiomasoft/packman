<?php

declare(strict_types=1);

namespace Packman\Modules\Events;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Events\DomainEvent;

/**
 * Retrofitted onto `DomainEvent` — was an interim plain-event class
 * dispatched on a successful `ModuleLifecycle::enable()`. Name matches the
 * domain-events table (`mod.module.enabled`).
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ModuleEnabled extends DomainEvent
{
    /** @param  list<string>  $migrationsRun */
    public function __construct(
        public string $module,
        public string $edition,
        public array $migrationsRun,
    ) {
        parent::__construct();
    }

    #[Override]
    public static function name(): string
    {
        return 'mod.module.enabled';
    }
}
