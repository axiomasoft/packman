<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Admission;

use Override;
use Packman\Attributes\Internal;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Contracts\Admission\PanelAdmissionGate;
use Packman\Panels\Contracts\Admission\PanelAdmitter;
use Packman\Panels\Data\Admission\AdmissionResult;
use Packman\Panels\Enums\Admission\AssignmentScopeMode;
use Packman\Panels\Enums\Admission\TenantMode;

/**
 * Evaluates entry admission into a panel shell, including tenant requirement, role holding,
 * graceful scoped navigation (allowing global shell access when an eligible child scope holds a role),
 * and panel entry permission checks.
 */
#[Internal]
final readonly class PanelAdmission implements PanelAdmitter
{
    public function __construct(private PanelAdmissionGate $gate) {}

    #[Override]
    public function admit(
        Panel $panel,
        object $subject,
        ContextScopeInterface $tenant,
        ?string $scopeType = null,
        ?string $scopeId = null,
    ): ?AdmissionResult {
        if (! $panel->accepts($subject)) {
            return null;
        }

        $isGlobalTenant = $tenant->id() === 'global' || $tenant->id() === 'system';

        if ($isGlobalTenant && $panel->tenantMode() === TenantMode::Required) {
            return null;
        }

        if ($panel->scopeMode() === AssignmentScopeMode::Required && ($scopeType === null || $scopeType === '' || $scopeId === null || $scopeId === '')) {
            return null;
        }

        if (! $this->gate->isMember($panel, $subject, $tenant, $scopeType, $scopeId)) {
            return null;
        }

        $scopedWitness = false;

        if (! $this->gate->hasRole($panel, $subject, $tenant, $scopeType, $scopeId)) {
            if (! $this->hasGracefulChildRole($panel, $subject, $tenant, $scopeType, $scopeId)) {
                return null;
            }

            $scopedWitness = true;
        }

        $entry = $panel->entry();

        if ($entry !== null && ! $this->gate->allowsEntry($panel, $subject, $tenant, $entry, $scopeType, $scopeId)) {
            return null;
        }

        return new AdmissionResult(
            tenant: $tenant,
            scopeType: $scopeType,
            scopeId: $scopeId,
            scopedWitness: $scopedWitness,
        );
    }

    private function hasGracefulChildRole(
        Panel $panel,
        object $subject,
        ContextScopeInterface $tenant,
        ?string $scopeType,
        ?string $scopeId,
    ): bool {
        if ($panel->scopeMode() !== AssignmentScopeMode::Inherit || $scopeType !== null || $scopeId !== null) {
            return false;
        }

        foreach ($panel->scopeTypes() as $type) {
            foreach ($this->gate->childScopesWithRole($panel, $subject, $tenant, $type) as $candidateId) {
                if (
                    $this->gate->isMember($panel, $subject, $tenant, $type, $candidateId)
                    && $this->gate->hasRole($panel, $subject, $tenant, $type, $candidateId)
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
