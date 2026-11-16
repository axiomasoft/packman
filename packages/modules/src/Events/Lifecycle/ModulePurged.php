<?php

declare(strict_types=1);

namespace Packman\Modules\Events\Lifecycle;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Events\DomainEvent;

/**
 * Retrofitted onto `DomainEvent` (see {@see ModuleEnabled}). `mod.module.purged`.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ModulePurged extends DomainEvent
{
    /** @param  list<string>  $droppedTables */
    public function __construct(
        public string $module,
        public array $droppedTables,
    ) {
        parent::__construct();
    }

    #[Override]
    public static function name(): string
    {
        return 'mod.module.purged';
    }
}
