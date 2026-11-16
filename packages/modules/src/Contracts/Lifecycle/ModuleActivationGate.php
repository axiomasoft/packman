<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts\Lifecycle;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Internal\Gate\ModuleActivationDecision;

/**
 * Cloud billing/edition gate. Boxed installs bind
 * {@see Packman\Modules\Internal\Gate\AllowAllActivationGate} — the cloud implementation (root_ billing)
 * is injected by the application, never known to this package.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ModuleActivationGate
{
    public function check(ContextScopeInterface $ctx, string $module, string $edition): ModuleActivationDecision;
}
