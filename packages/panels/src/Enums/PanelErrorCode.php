<?php

declare(strict_types=1);

namespace Packman\Panels\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

#[Api(since: '1.0')]
enum PanelErrorCode: string implements ErrorCode
{
    case DefinitionError = 'panels.definition_error';
    case DuplicatePanel = 'panels.duplicate_panel';
    case UnknownPanel = 'panels.unknown_panel';
    case InvalidPanelId = 'panels.invalid_panel_id';
    case RegistryFrozen = 'panels.registry_frozen';
    case DefaultPanelConflict = 'panels.default_panel_conflict';
    case PrefixConflict = 'panels.prefix_conflict';
    case PluginConflict = 'panels.plugin_conflict';
    case DuplicatePermission = 'panels.duplicate_permission';
    case DuplicateRole = 'panels.duplicate_role';
    case InvalidPermissionKey = 'panels.invalid_permission_key';
    case UnknownPermission = 'panels.unknown_permission';
    case UnknownSlot = 'panels.unknown_slot';
    case SubjectResolutionFailed = 'panels.subject_resolution_failed';
    case PolicyConflict = 'panels.policy_conflict';
    case SavedViewConflict = 'panels.saved_view_conflict';
    case SavedViewNotFound = 'panels.saved_view_not_found';
    case SavedViewAccessDenied = 'panels.saved_view_access_denied';
    case InvalidSavedView = 'panels.invalid_saved_view';
    case InvalidConfiguration = 'panels.invalid_configuration';

    public function value(): string
    {
        return $this->value;
    }
}
