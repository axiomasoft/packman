<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions\Subjects;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;
use Packman\Panels\Exceptions\PanelException;

#[Api(since: '1.0')]
final class SubjectResolutionFailed extends PanelException
{
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PanelErrorCode::SubjectResolutionFailed, category: ErrorCategory::Conflict, retryable: false);
    }
}
