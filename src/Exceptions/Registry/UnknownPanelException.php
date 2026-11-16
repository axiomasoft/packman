<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions\Registry;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;
use Packman\Panels\Exceptions\Definition\DefinitionException;

/**
 * No panel is registered under the requested id.
 */
#[Api(since: '1.0')]
final class UnknownPanelException extends DefinitionException
{
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PanelErrorCode::UnknownPanel, category: ErrorCategory::NotFound, retryable: false);
    }
}
