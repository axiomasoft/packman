<?php

declare(strict_types=1);

namespace Packman\Panels\Contracts\Admission;

use Packman\Attributes\Api;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Data\Admission\AdmissionResult;

/**
 * Evaluates subject admission into a panel shell across tenant, scope, and permission checks.
 */
#[Api(since: '1.0')]
interface PanelAdmitter
{
    public function admit(
        Panel $panel,
        object $subject,
        ContextScopeInterface $tenant,
        ?string $scopeType = null,
        ?string $scopeId = null,
    ): ?AdmissionResult;
}
