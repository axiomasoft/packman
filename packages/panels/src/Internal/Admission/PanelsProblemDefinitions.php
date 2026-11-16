<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Admission;

use Packman\Attributes\Internal;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;

#[Internal]
final readonly class PanelsProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(private string $urnPrefix = 'urn:packman:problem:') {}

    public function definitions(): iterable
    {
        yield new ProblemDefinition(code: PanelErrorCode::DefinitionError, type: $this->urnPrefix.'panels.definition_error', defaultStatus: 422, title: 'Invalid panel definition.', retryable: false, category: ErrorCategory::Validation);
        yield new ProblemDefinition(code: PanelErrorCode::DuplicatePanel, type: $this->urnPrefix.'panels.duplicate_panel', defaultStatus: 409, title: 'Duplicate panel registration.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: PanelErrorCode::UnknownPanel, type: $this->urnPrefix.'panels.unknown_panel', defaultStatus: 404, title: 'Unknown panel.', retryable: false, category: ErrorCategory::NotFound);
        yield new ProblemDefinition(code: PanelErrorCode::InvalidPanelId, type: $this->urnPrefix.'panels.invalid_panel_id', defaultStatus: 422, title: 'Invalid panel identifier.', retryable: false, category: ErrorCategory::Validation);
        yield new ProblemDefinition(code: PanelErrorCode::RegistryFrozen, type: $this->urnPrefix.'panels.registry_frozen', defaultStatus: 409, title: 'Panel registry is frozen.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: PanelErrorCode::DefaultPanelConflict, type: $this->urnPrefix.'panels.default_panel_conflict', defaultStatus: 409, title: 'Conflicting default panels.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: PanelErrorCode::PrefixConflict, type: $this->urnPrefix.'panels.prefix_conflict', defaultStatus: 409, title: 'Conflicting panel resource prefixes.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: PanelErrorCode::PluginConflict, type: $this->urnPrefix.'panels.plugin_conflict', defaultStatus: 409, title: 'Conflicting panel plugin settings.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: PanelErrorCode::DuplicatePermission, type: $this->urnPrefix.'panels.duplicate_permission', defaultStatus: 409, title: 'Duplicate panel permission.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: PanelErrorCode::DuplicateRole, type: $this->urnPrefix.'panels.duplicate_role', defaultStatus: 409, title: 'Duplicate panel role.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: PanelErrorCode::InvalidPermissionKey, type: $this->urnPrefix.'panels.invalid_permission_key', defaultStatus: 422, title: 'Invalid permission key.', retryable: false, category: ErrorCategory::Validation);
        yield new ProblemDefinition(code: PanelErrorCode::UnknownPermission, type: $this->urnPrefix.'panels.unknown_permission', defaultStatus: 404, title: 'Unknown panel permission.', retryable: false, category: ErrorCategory::NotFound);
        yield new ProblemDefinition(code: PanelErrorCode::UnknownSlot, type: $this->urnPrefix.'panels.unknown_slot', defaultStatus: 404, title: 'Unknown panel slot.', retryable: false, category: ErrorCategory::NotFound);
        yield new ProblemDefinition(code: PanelErrorCode::SubjectResolutionFailed, type: $this->urnPrefix.'panels.subject_resolution_failed', defaultStatus: 409, title: 'Subject chain resolution failed.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: PanelErrorCode::PolicyConflict, type: $this->urnPrefix.'panels.policy_conflict', defaultStatus: 409, title: 'Panel policy conflict.', retryable: true, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: PanelErrorCode::SavedViewConflict, type: $this->urnPrefix.'panels.saved_view_conflict', defaultStatus: 409, title: 'Saved view revision conflict.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: PanelErrorCode::SavedViewNotFound, type: $this->urnPrefix.'panels.saved_view_not_found', defaultStatus: 404, title: 'Saved view not found.', retryable: false, category: ErrorCategory::NotFound);
        yield new ProblemDefinition(code: PanelErrorCode::SavedViewAccessDenied, type: $this->urnPrefix.'panels.saved_view_access_denied', defaultStatus: 403, title: 'Saved view action denied.', retryable: false, category: ErrorCategory::Authorization);
        yield new ProblemDefinition(code: PanelErrorCode::InvalidSavedView, type: $this->urnPrefix.'panels.invalid_saved_view', defaultStatus: 422, title: 'Invalid saved view input.', retryable: false, category: ErrorCategory::Validation);
        yield new ProblemDefinition(code: PanelErrorCode::InvalidConfiguration, type: $this->urnPrefix.'panels.invalid_configuration', defaultStatus: 500, title: 'Invalid panels configuration.', retryable: false, category: ErrorCategory::Internal);
    }
}
