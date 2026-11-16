<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Subjects;

use Override;
use Packman\Attributes\Internal;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Contracts\Admission\PanelPolicyResolver;
use Packman\Panels\Contracts\Subjects\PanelFormProjector;
use Packman\Panels\Contracts\Subjects\SubjectChainResolver;
use Packman\Panels\Data\Panel\PanelFormData;
use Packman\Panels\Data\Subjects\SubjectNode;
use Packman\Panels\Exceptions\Permissions\PolicyConflictException;
use Packman\Panels\Exceptions\Subjects\SubjectResolutionFailed;

/**
 * Headless form projection service clamping effective settings and attaching optimistic concurrency `editVersion`.
 */
#[Internal]
final readonly class PanelFormProjection implements PanelFormProjector
{
    public function __construct(
        private SubjectChainResolver $subjects,
        private PanelPolicyResolver $policies,
    ) {}

    #[Override]
    public function project(Panel $panel, SubjectNode $subject, string $slotKey): PanelFormData
    {
        $slot = $panel->slot($slotKey);

        try {
            $chain = $this->subjects->resolve($subject);
        } catch (SubjectResolutionFailed $failure) {
            return new PanelFormData($panel->id(), $slotKey, null, [], $failure->getMessage());
        }

        if (! $this->policies->canView($chain, $panel->id(), $slotKey)) {
            return new PanelFormData($panel->id(), $slotKey, null, [], 'permission_denied');
        }

        $version = $this->policies->editVersion($subject->tenantId);
        $binding = $this->policies->findBinding($chain, $panel->id(), $slotKey);

        if (! $slot->inheritDefaults && ($binding === null || ($binding['retired'] ?? false) === true)) {
            return new PanelFormData($panel->id(), $slotKey, $binding, [], 'Explicit binding required.');
        }

        $editable = $this->policies->canUpdate($chain, $panel->id(), $slotKey);
        $resolved = $this->policies->resolveFields($chain, $slot, $panel->id());
        $fields = [];

        foreach ($slot->settings as $key) {
            $entry = $resolved[$key] ?? ['value' => $slot->defaults[$key] ?? null];

            if (($entry['sensitive'] ?? false) === true) {
                continue;
            }

            $fields[$key] = PanelFormData::clampField(
                value: $entry['value'],
                inherited: $entry['inherited'] ?? true,
                source: $entry['source'] ?? 'slot_defaults',
                revision: $entry['revision'] ?? 1,
                minimum: $entry['minimum'] ?? null,
                maximum: $entry['maximum'] ?? null,
                clampedBy: $entry['clamped_by'] ?? null,
                clampedRevision: $entry['clamped_revision'] ?? null,
                editable: $editable && ($entry['editable'] ?? true),
                reason: $editable ? ($entry['reason'] ?? 'ok') : 'permission_denied',
            );
        }

        if (! hash_equals($version, $this->policies->editVersion($subject->tenantId))) {
            throw new PolicyConflictException('Policy state changed during form projection.');
        }

        return new PanelFormData(
            panelKey: $panel->id(),
            slotKey: $slotKey,
            binding: $binding,
            fields: $fields,
            editVersion: $version,
        );
    }
}
