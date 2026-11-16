<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Errors;

use Packman\Attributes\Internal;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Modules\Enums\ModuleErrorCode;

#[Internal]
final readonly class ModulesProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(private string $urnPrefix = 'urn:packman:problem:') {}

    public function definitions(): iterable
    {
        yield new ProblemDefinition(code: ModuleErrorCode::CompilationException, type: $this->urnPrefix.'modules.compilation_failed', defaultStatus: 500, title: 'Module registry compilation failed.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: ModuleErrorCode::EntityNotFound, type: $this->urnPrefix.'modules.entity_not_found', defaultStatus: 404, title: 'Module entity not found.', retryable: false, category: ErrorCategory::NotFound);
        yield new ProblemDefinition(code: ModuleErrorCode::HasEnabledDependentsException, type: $this->urnPrefix.'modules.has_enabled_dependents', defaultStatus: 409, title: 'Module has enabled dependents.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: ModuleErrorCode::InvalidManifestException, type: $this->urnPrefix.'modules.invalid_manifest', defaultStatus: 422, title: 'Invalid module manifest.', retryable: false, category: ErrorCategory::Validation);
        yield new ProblemDefinition(code: ModuleErrorCode::InvalidRegistryPoint, type: $this->urnPrefix.'modules.invalid_registry_point', defaultStatus: 422, title: 'Invalid module registry point.', retryable: false, category: ErrorCategory::Validation);
        yield new ProblemDefinition(code: ModuleErrorCode::InvalidStateTransitionException, type: $this->urnPrefix.'modules.invalid_state_transition', defaultStatus: 409, title: 'Invalid module lifecycle transition.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: ModuleErrorCode::MissingDependencyException, type: $this->urnPrefix.'modules.missing_dependency', defaultStatus: 409, title: 'Missing required module dependency.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: ModuleErrorCode::ModuleEnableFailedException, type: $this->urnPrefix.'modules.enable_failed', defaultStatus: 500, title: 'Enabling module failed.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: ModuleErrorCode::ModuleNotFound, type: $this->urnPrefix.'modules.module_not_found', defaultStatus: 404, title: 'Module not found.', retryable: false, category: ErrorCategory::NotFound);
        yield new ProblemDefinition(code: ModuleErrorCode::ModuleUpgradeFailedException, type: $this->urnPrefix.'modules.upgrade_failed', defaultStatus: 500, title: 'Upgrading module failed.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: ModuleErrorCode::RegistryFrozen, type: $this->urnPrefix.'modules.registry_frozen', defaultStatus: 500, title: 'Entity registry is frozen.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: ModuleErrorCode::RegistryInputUnavailable, type: $this->urnPrefix.'modules.registry_input_unavailable', defaultStatus: 503, title: 'Compiled module registry is unavailable.', retryable: false, category: ErrorCategory::Unavailable);
        yield new ProblemDefinition(code: ModuleErrorCode::StaleRegistryRevision, type: $this->urnPrefix.'modules.stale_registry_revision', defaultStatus: 409, title: 'Stale module registry revision.', retryable: false, category: ErrorCategory::Conflict);
    }
}
