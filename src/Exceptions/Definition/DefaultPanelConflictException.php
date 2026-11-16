<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions\Definition;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;

/**
 * Two panels are declared as the default panel of the same subject model.
 */
#[Api(since: '1.0')]
final class DefaultPanelConflictException extends DefinitionException
{
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PanelErrorCode::DefaultPanelConflict, category: ErrorCategory::Conflict, retryable: false);
    }
}
