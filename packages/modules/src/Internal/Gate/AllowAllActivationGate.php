<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Gate;

use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Contracts\Lifecycle\ModuleActivationGate;
use Packman\Modules\Data\Lifecycle\ModuleActivationDecision;

/**
 * Boxed default — no billing gate exists outside cloud, so every
 * module/edition is allowed. The application injects a cloud-aware
 * implementation for SaaS installs.
 *
 * @internal spec: D10
 */
final class AllowAllActivationGate implements ModuleActivationGate
{
    public function check(ContextScopeInterface $ctx, string $module, string $edition): ModuleActivationDecision
    {
        return ModuleActivationDecision::allow();
    }
}
