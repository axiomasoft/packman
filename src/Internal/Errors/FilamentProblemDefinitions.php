<?php

declare(strict_types=1);

namespace Packman\Filament\Internal\Errors;

use Packman\Attributes\Internal;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Filament\Enums\FilamentErrorCode;

#[Internal]
final readonly class FilamentProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(private string $urnPrefix = 'urn:packman:problem:') {}

    public function definitions(): iterable
    {
        yield new ProblemDefinition(code: FilamentErrorCode::AuthorizationDenied, type: $this->urnPrefix.'filament.authorization_denied', defaultStatus: 403, title: 'Filament action authorization denied.', retryable: false, category: ErrorCategory::Authorization);
        yield new ProblemDefinition(code: FilamentErrorCode::InvalidContext, type: $this->urnPrefix.'filament.invalid_context', defaultStatus: 403, title: 'Invalid or tampered Filament context.', retryable: false, category: ErrorCategory::Authorization);
        yield new ProblemDefinition(code: FilamentErrorCode::InvalidConfiguration, type: $this->urnPrefix.'filament.invalid_configuration', defaultStatus: 500, title: 'Invalid Filament plugin configuration.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: FilamentErrorCode::NonEditableField, type: $this->urnPrefix.'filament.non_editable_field', defaultStatus: 403, title: 'Field cannot be edited.', retryable: false, category: ErrorCategory::Authorization);
        yield new ProblemDefinition(code: FilamentErrorCode::SurfaceRefused, type: $this->urnPrefix.'filament.surface_refused', defaultStatus: 403, title: 'Filament surface access refused.', retryable: false, category: ErrorCategory::Authorization);
        yield new ProblemDefinition(code: FilamentErrorCode::PolicyConflict, type: $this->urnPrefix.'filament.policy_conflict', defaultStatus: 409, title: 'Binding policy state conflict.', retryable: true, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: FilamentErrorCode::ValidationFailed, type: $this->urnPrefix.'filament.validation_failed', defaultStatus: 422, title: 'Invalid binding submission.', retryable: false, category: ErrorCategory::Validation);
    }
}
