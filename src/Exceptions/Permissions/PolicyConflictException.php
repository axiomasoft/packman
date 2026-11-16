<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions\Permissions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;
use Packman\Panels\Exceptions\PanelException;

#[Api(since: '1.0')]
final class PolicyConflictException extends PanelException
{
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PanelErrorCode::PolicyConflict, category: ErrorCategory::Conflict, retryable: true);
    }
}
