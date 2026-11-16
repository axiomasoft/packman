<?php

declare(strict_types=1);

namespace Packman\Errors\Internal;

use Packman\Attributes\Internal;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Enums\ErrorCategory;

#[Internal]
final readonly class PackageProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(private string $urnPrefix = 'urn:packman:problem:') {}

    public function definitions(): iterable
    {
        yield new ProblemDefinition(code: CoreErrorCode::DuplicateCode, type: $this->urnPrefix.'packman.duplicate_code', defaultStatus: 500, title: 'Duplicate error code.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::DuplicateType, type: $this->urnPrefix.'packman.duplicate_type', defaultStatus: 500, title: 'Duplicate problem type.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::ForeignPrefix, type: $this->urnPrefix.'packman.foreign_prefix', defaultStatus: 500, title: 'Foreign error prefix.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::InternalError, type: $this->urnPrefix.'packman.internal_error', defaultStatus: 500, title: 'Internal error.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::InvalidConfiguration, type: $this->urnPrefix.'packman.invalid_configuration', defaultStatus: 500, title: 'Invalid configuration.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::InvalidDefinition, type: $this->urnPrefix.'packman.invalid_definition', defaultStatus: 500, title: 'Invalid error definition.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::InvalidScopePayload, type: $this->urnPrefix.'packman.invalid_scope_payload', defaultStatus: 422, title: 'Invalid scope payload.', retryable: false, category: ErrorCategory::Validation);
        yield new ProblemDefinition(code: CoreErrorCode::InvalidStatus, type: $this->urnPrefix.'packman.invalid_status', defaultStatus: 500, title: 'Invalid error status.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::RegistryFrozen, type: $this->urnPrefix.'packman.registry_frozen', defaultStatus: 500, title: 'Problem registry is frozen.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::ScopeUnavailable, type: $this->urnPrefix.'packman.scope_unavailable', defaultStatus: 409, title: 'Scope context unavailable.', retryable: false, category: ErrorCategory::Conflict);
        yield new ProblemDefinition(code: CoreErrorCode::UnknownCode, type: $this->urnPrefix.'packman.unknown_code', defaultStatus: 500, title: 'Unknown error code.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::UnsupportedScope, type: $this->urnPrefix.'packman.unsupported_scope', defaultStatus: 422, title: 'Unsupported scope.', retryable: false, category: ErrorCategory::Unsupported);
        yield new ProblemDefinition(code: CoreErrorCode::SchemaInvalidSet, type: $this->urnPrefix.'packman.schema_invalid_set', defaultStatus: 500, title: 'Schema set is invalid.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::SchemaDuplicateSet, type: $this->urnPrefix.'packman.schema_duplicate_set', defaultStatus: 500, title: 'Duplicate schema set.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::SchemaDuplicateMigration, type: $this->urnPrefix.'packman.schema_duplicate_migration', defaultStatus: 500, title: 'Duplicate schema migration.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: CoreErrorCode::SchemaUnknownSet, type: $this->urnPrefix.'packman.schema_unknown_set', defaultStatus: 404, title: 'Unknown schema set.', retryable: false, category: ErrorCategory::NotFound);
        yield new ProblemDefinition(code: CoreErrorCode::SchemaUnsupportedDriver, type: $this->urnPrefix.'packman.schema_unsupported_driver', defaultStatus: 422, title: 'Unsupported database driver.', retryable: false, category: ErrorCategory::Unsupported);
    }
}
