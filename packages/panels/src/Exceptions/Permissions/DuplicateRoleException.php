<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions\Permissions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;
use Packman\Panels\Exceptions\Definition\DefinitionException;

#[Api(since: '1.0')]
final class DuplicateRoleException extends DefinitionException
{
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PanelErrorCode::DuplicateRole, category: ErrorCategory::Conflict, retryable: false);
    }
}
