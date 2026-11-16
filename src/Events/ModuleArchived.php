<?php

declare(strict_types=1);

namespace Packman\Modules\Events;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Events\DomainEvent;

/**
 * Retrofitted onto `DomainEvent` (see {@see ModuleEnabled}). `mod.module.archived`.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ModuleArchived extends DomainEvent
{
    public function __construct(public string $module)
    {
        parent::__construct();
    }

    #[Override]
    public static function name(): string
    {
        return 'mod.module.archived';
    }
}
