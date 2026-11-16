<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions\Definition;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;

/**
 * A panel id is registered twice; an intended replacement uses `replace()`.
 */
#[Api(since: '1.0')]
final class DuplicatePanelException extends DefinitionException
{
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PanelErrorCode::DuplicatePanel, category: ErrorCategory::Conflict, retryable: false);
    }
}
