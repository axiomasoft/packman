<?php

declare(strict_types=1);

namespace Packman\Modules\Events\Lifecycle;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Events\DomainEvent;

/**
 * Retrofitted onto `DomainEvent` (see {@see ModuleEnabled}). `mod.module.upgraded`.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ModuleUpgraded extends DomainEvent
{
    /** @param  list<string>  $migrationsRun */
    public function __construct(
        public string $module,
        public string $fromVersion,
        public string $toVersion,
        public array $migrationsRun,
    ) {
        parent::__construct();
    }

    #[Override]
    public static function name(): string
    {
        return 'mod.module.upgraded';
    }
}
