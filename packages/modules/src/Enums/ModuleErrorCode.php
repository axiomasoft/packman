<?php

declare(strict_types=1);

namespace Packman\Modules\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

#[Api(since: '1.0')]
enum ModuleErrorCode: string implements ErrorCode
{
    case CompilationException = 'modules.compilation_failed';
    case EntityNotFound = 'modules.entity_not_found';
    case HasEnabledDependentsException = 'modules.has_enabled_dependents';
    case InvalidManifestException = 'modules.invalid_manifest';
    case InvalidRegistryPoint = 'modules.invalid_registry_point';
    case InvalidStateTransitionException = 'modules.invalid_state_transition';
    case MissingDependencyException = 'modules.missing_dependency';
    case ModuleEnableFailedException = 'modules.enable_failed';
    case ModuleNotFound = 'modules.module_not_found';
    case ModuleUpgradeFailedException = 'modules.upgrade_failed';
    case RegistryFrozen = 'modules.registry_frozen';
    case RegistryInputUnavailable = 'modules.registry_input_unavailable';
    case StaleRegistryRevision = 'modules.stale_registry_revision';

    public function value(): string
    {
        return $this->value;
    }
}
