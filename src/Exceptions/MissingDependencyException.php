<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Enums\ModuleErrorCode;

#[Api(since: '1.0')]
final class MissingDependencyException extends CodedException
{
    public function __construct(string $module, string $missingDependency)
    {
        parent::__construct(sprintf(
            'Module [%s] requires [%s], which is not enabled. Pass cascade: true to auto-enable it.',
            $module,
            $missingDependency,
        ));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::MissingDependencyException, category: ErrorCategory::Conflict, retryable: false);
    }
}
