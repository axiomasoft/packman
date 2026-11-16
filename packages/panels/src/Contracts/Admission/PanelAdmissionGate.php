<?php

declare(strict_types=1);

namespace Packman\Panels\Contracts\Admission;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Panels\Builder\Panel;
use UnitEnum;

/**
 * Host authorization adapter evaluated by `PanelAdmission` to admit subjects into a panel shell.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PanelAdmissionGate
{
    public function isMember(
        Panel $panel,
        object $subject,
        ContextScopeInterface $tenant,
        ?string $scopeType = null,
        ?string $scopeId = null,
    ): bool;

    public function hasRole(
        Panel $panel,
        object $subject,
        ContextScopeInterface $tenant,
        ?string $scopeType = null,
        ?string $scopeId = null,
    ): bool;

    /**
     * Child scope identifiers within the given scope type where the subject holds a qualifying role.
     *
     * @return list<string>
     */
    public function childScopesWithRole(
        Panel $panel,
        object $subject,
        ContextScopeInterface $tenant,
        string $scopeType,
    ): array;

    public function allowsEntry(
        Panel $panel,
        object $subject,
        ContextScopeInterface $tenant,
        string|UnitEnum $permission,
        ?string $scopeType = null,
        ?string $scopeId = null,
    ): bool;
}
