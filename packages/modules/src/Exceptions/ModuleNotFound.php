<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Enums\ModuleErrorCode;

#[Api(since: '1.0')]
final class ModuleNotFound extends CodedException
{
    public function __construct(string $composerName)
    {
        parent::__construct(sprintf('Module [%s] is not in the compiled registry.', $composerName));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::ModuleNotFound, category: ErrorCategory::NotFound, retryable: false);
    }
}
