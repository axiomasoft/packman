<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Data\ModuleRuntimeContext;

/**
 * Module-authored non-standard cleanup on purge — external webhooks, media
 * files — anything outside what `mod_records` can drop by itself.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PurgeHook
{
    public function __invoke(ModuleRuntimeContext $ctx): void;
}
