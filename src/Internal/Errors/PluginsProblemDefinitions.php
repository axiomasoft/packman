<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Errors;

use Packman\Attributes\Internal;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Plugins\Enums\PluginErrorCode;

#[Internal]
final readonly class PluginsProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(private string $urnPrefix = 'urn:packman:problem:') {}

    public function definitions(): iterable
    {
        yield new ProblemDefinition(code: PluginErrorCode::InvalidGraph, type: $this->urnPrefix.'plugins.invalid_graph', defaultStatus: 500, title: 'Invalid plugin extension graph.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: PluginErrorCode::MissingExtension, type: $this->urnPrefix.'plugins.missing_extension', defaultStatus: 422, title: 'Required plugin extension is missing.', retryable: false, category: ErrorCategory::Unsupported);
        yield new ProblemDefinition(code: PluginErrorCode::RegistryFrozen, type: $this->urnPrefix.'plugins.registry_frozen', defaultStatus: 500, title: 'Plugin registry is frozen.', retryable: false, category: ErrorCategory::Internal);
        yield new ProblemDefinition(code: PluginErrorCode::UnsupportedContribution, type: $this->urnPrefix.'plugins.unsupported_contribution', defaultStatus: 422, title: 'Unsupported plugin contribution.', retryable: false, category: ErrorCategory::Unsupported, detailKeys: ['kind', 'key', 'reason']);
    }
}
