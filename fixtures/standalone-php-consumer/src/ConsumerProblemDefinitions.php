<?php

declare(strict_types=1);

namespace Fixture\StandalonePhp;

use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

final class ConsumerProblemDefinitions implements ProblemDefinitionProvider
{
    public function definitions(): array
    {
        return [
            new ProblemDefinition(
                code: ConsumerErrorCode::InvalidPayload,
                type: 'urn:packman:problem:consumer.invalid_payload',
                defaultStatus: 422,
                title: 'Invalid consumer payload.',
                retryable: false,
                category: ErrorCategory::Validation,
                detailKeys: ['field'],
            ),
        ];
    }
}
