<?php

declare(strict_types=1);

namespace Packman\Panels\Contracts\Admission;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Data\Panel\PanelSlot;
use Packman\Panels\Data\Subjects\SubjectChain;

/**
 * Host policy evaluator backing `PanelFormProjection` for slot-based form fields.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PanelPolicyResolver
{
    public function canView(SubjectChain $chain, string $panelKey, string $slotKey): bool;

    public function canUpdate(SubjectChain $chain, string $panelKey, string $slotKey): bool;

    public function editVersion(string $tenantId): string;

    /**
     * @return array<string, mixed>|null
     */
    public function findBinding(SubjectChain $chain, string $panelKey, string $slotKey): ?array;

    /**
     * Resolve effective slot values and optional bounds for each setting in the slot.
     *
     * @return array<string, array{
     *     value: mixed,
     *     inherited?: bool,
     *     source?: string,
     *     revision?: int,
     *     minimum?: int|float|null,
     *     maximum?: int|float|null,
     *     clamped_by?: ?string,
     *     clamped_revision?: ?int,
     *     editable?: bool,
     *     reason?: string,
     *     sensitive?: bool,
     * }>
     */
    public function resolveFields(SubjectChain $chain, PanelSlot $slot, string $panelKey): array;
}
