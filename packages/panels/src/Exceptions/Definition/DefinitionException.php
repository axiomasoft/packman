<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions\Definition;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;
use Packman\Panels\Exceptions\PanelException;

/**
 * A panel, slot, role, or plugin is declared in a way the compiler cannot build.
 */
#[Api(since: '1.0')]
class DefinitionException extends PanelException
{
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PanelErrorCode::DefinitionError, category: ErrorCategory::Validation, retryable: false);
    }
}
