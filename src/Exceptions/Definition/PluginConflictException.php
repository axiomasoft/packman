<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions\Definition;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;

#[Api(since: '1.0')]
final class PluginConflictException extends DefinitionException
{
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PanelErrorCode::PluginConflict, category: ErrorCategory::Conflict, retryable: false);
    }
}
